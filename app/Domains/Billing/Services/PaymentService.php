<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Billing\Enums\InvoiceStatus;
use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Enums\PaymentStatus;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Models\PaymentAllocation;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Organization\Enums\DocumentType;
use App\Domains\Partners\Enums\PartnerType;
use App\Domains\Partners\Models\Partner;
use App\Support\Documents\ManagesDocuments;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Math\Decimal as D;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Payments recorded by finance after the money has been verified (CP-1.1 section 5.6). This is the
 * only code that changes an invoice's paid amount and balance, always inside one transaction with
 * row locks; the same bank/mobile transaction reference can be recorded once (unique index), and
 * payments and allocations are append-only — voids and reversals are recorded, never deleted.
 */
final class PaymentService
{
    use ManagesDocuments;

    /**
     * Record a verified payment (finance; also used when verifying a customer's confirmation).
     *
     * @param  array<string, mixed>  $data  validated against PaymentSchema
     */
    public function record(array $data, UserAccount $actor, ?string $confirmationId = null): Payment
    {
        $customer = Partner::query()->find($data['customer_partner_id'] ?? null);
        if ($customer === null || ! $customer->hasType(PartnerType::CUSTOMER)) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Choose the customer who paid.', ['field' => 'customer_partner_id']);
        }
        if (D::lte((string) $data['amount'], '0')) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'The amount must be greater than zero.', ['field' => 'amount']);
        }
        $reference = isset($data['bank_reference']) && trim((string) $data['bank_reference']) !== '' ? trim((string) $data['bank_reference']) : null;
        if ($reference !== null && ($existing = $this->postedWithReference($reference)) !== null) {
            throw DomainException::of(ErrorCode::DUPLICATE_RESOURCE, "Transaction reference $reference is already recorded as payment $existing.", ['field' => 'bank_reference']);
        }
        try {
            return DB::transaction(function () use ($data, $actor, $confirmationId, $customer, $reference) {
                $payment = Payment::query()->create([
                    'document_number' => $this->nextNumber(DocumentType::PAYMENT), 'customer_partner_id' => $customer->id,
                    'amount' => D::exact((string) $data['amount'], D::MONEY_SCALE), 'currency' => $data['currency'] ?? $customer->default_currency,
                    'received_on' => $data['received_on'] ?? now()->toDateString(), 'method' => PaymentMethod::from((string) ($data['method'] ?? 'bank_transfer')),
                    'bank_reference' => $reference, 'payment_confirmation_id' => $confirmationId, 'notes' => $data['notes'] ?? null,
                    'status' => PaymentStatus::POSTED, 'recorded_by' => $actor->id,
                ]);
                $this->audit($actor, 'billing.payment.recorded', $payment, null, ['amount' => (string) $payment->amount, 'currency' => $payment->currency,
                    'reference' => $reference, 'confirmation_id' => $confirmationId]);

                return $payment;
            });
        } catch (UniqueConstraintViolationException) {
            throw DomainException::of(ErrorCode::DUPLICATE_RESOURCE, 'This payment was already recorded (same transaction reference or confirmation).', ['field' => 'bank_reference']);
        }
    }

    /** Apply part of a payment to an invoice. */
    public function allocate(string $id, UserAccount $actor, string $invoiceId, string $amount): PaymentAllocation
    {
        if (D::lte($amount, '0')) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'The amount must be greater than zero.', ['field' => 'amount']);
        }

        return DB::transaction(function () use ($id, $actor, $invoiceId, $amount) {
            /** @var Payment $payment */
            $payment = $this->lockDocument(Payment::class, $id);
            /** @var Invoice $invoice */
            $invoice = $this->lockDocument(Invoice::class, $invoiceId);
            if ($payment->status !== PaymentStatus::POSTED) {
                throw DomainException::of(ErrorCode::INVALID_STATE_TRANSITION, 'Only posted payments can be allocated.');
            }
            if ($invoice->customer_partner_id !== $payment->customer_partner_id || $invoice->currency !== $payment->currency) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'The invoice belongs to another customer or currency.', ['field' => 'invoice_id']);
            }
            $this->assertStatus($invoice, [InvoiceStatus::ISSUED, InvoiceStatus::PARTIALLY_PAID]);
            $unallocated = D::sub((string) $payment->amount, $this->allocated($payment->id), D::MONEY_SCALE);
            if (D::gt($amount, $unallocated) || D::gt($amount, (string) $invoice->balance_due)) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'At most '.D::min($unallocated, (string) $invoice->balance_due).' can be allocated.',
                    ['field' => 'amount', 'unallocated' => $unallocated, 'balance_due' => (string) $invoice->balance_due]);
            }
            $allocation = PaymentAllocation::query()->create(['payment_id' => $payment->id, 'invoice_id' => $invoice->id,
                'amount' => D::exact($amount, D::MONEY_SCALE), 'allocated_by' => $actor->id, 'created_at' => now()]);
            $this->applyToInvoice($invoice, $amount);
            $this->audit($actor, 'billing.payment.allocated', $payment, null, ['invoice' => $invoice->document_number, 'amount' => $amount]);
            $this->publish('billing.payment.allocated', $invoice, ['document_number' => $invoice->document_number, 'customer_partner_id' => $invoice->customer_partner_id,
                'balance_due' => (string) $invoice->balance_due, 'status' => $invoice->status->value]);

            return $allocation;
        });
    }

    /** Void a payment: its allocations are reversed (invoice balances restored) and the record kept. */
    public function void(string $id, UserAccount $actor, ?string $reason): Payment
    {
        if (trim((string) $reason) === '') {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Give the reason for voiding.', ['field' => 'reason']);
        }

        return DB::transaction(function () use ($id, $actor, $reason) {
            /** @var Payment $payment */
            $payment = $this->lockDocument(Payment::class, $id);
            if ($payment->status !== PaymentStatus::POSTED) {
                throw DomainException::of(ErrorCode::INVALID_STATE_TRANSITION, 'This payment is already void.');
            }
            foreach (PaymentAllocation::query()->where('payment_id', $payment->id)->whereNull('reversed_at')->get() as $allocation) {
                /** @var Invoice $invoice */
                $invoice = $this->lockDocument(Invoice::class, $allocation->invoice_id);
                $allocation->forceFill(['reversed_by' => $actor->id, 'reversed_at' => now(), 'reversal_reason' => $reason])->save();
                $this->applyToInvoice($invoice, D::neg((string) $allocation->amount, D::MONEY_SCALE));
            }
            $payment->forceFill(['status' => PaymentStatus::VOID, 'voided_by' => $actor->id, 'voided_at' => now(), 'void_reason' => $reason])->save();
            $this->audit($actor, 'billing.payment.voided', $payment, null, null, $reason);

            return $payment;
        });
    }

    /** Amount of a payment currently allocated. */
    public function allocated(string $paymentId): string
    {
        return D::exact((string) PaymentAllocation::query()->where('payment_id', $paymentId)->whereNull('reversed_at')->sum('amount'), D::MONEY_SCALE);
    }

    public function postedWithReference(string $reference): ?string
    {
        return Payment::query()->where('status', PaymentStatus::POSTED->value)->whereRaw('lower(bank_reference) = ?', [mb_strtolower(trim($reference))])->value('document_number');
    }

    /** Move the invoice's paid amount/balance by `$delta` (negative for reversals) and derive its status. */
    private function applyToInvoice(Invoice $invoice, string $delta): void
    {
        $paid = D::add((string) $invoice->amount_paid, $delta, D::MONEY_SCALE);
        $balance = D::sub((string) $invoice->total, $paid, D::MONEY_SCALE);
        $status = D::isZero($balance) ? InvoiceStatus::PAID : (D::isZero($paid) ? InvoiceStatus::ISSUED : InvoiceStatus::PARTIALLY_PAID);
        $extra = ['amount_paid' => $paid, 'balance_due' => $balance];
        $status === $invoice->status ? $invoice->forceFill($extra + ['version' => $invoice->version + 1])->save() : $this->transition($invoice, $status, $extra);
    }
}
