<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Billing\Enums\InvoiceStatus;
use App\Domains\Billing\Enums\PaymentConfirmationStatus as S;
use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\PaymentConfirmation;
use App\Domains\Catalog\Enums\AttachmentKind;
use App\Domains\Fulfillment\Enums\SalesOrderStatus;
use App\Domains\Fulfillment\Models\SalesOrder;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Organization\Enums\DocumentType;
use App\Domains\Partners\Models\Partner;
use App\Domains\Platform\Config\PlatformConfig;
use App\Domains\Platform\Enums\AttachableType;
use App\Support\Documents\ManagesDocuments;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Math\Decimal as D;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Customer payment confirmations (CP-1.1 section 5.6). A submission with its slip or screenshot is
 * supporting evidence only: it never changes an invoice, an order or stock. Finance check that the
 * money reached the company and then verify (which records the payment and allocates it), ask for
 * more information, or reject. Every step is audited.
 */
final class PaymentConfirmationWorkflow
{
    use ManagesDocuments;

    public function __construct(private readonly EvidenceStore $evidence, private readonly PaymentService $payments) {}

    /**
     * @param  array<string, mixed>  $data  validated against PaymentConfirmationSchema
     * @param  array<int, UploadedFile>  $files
     */
    public function submit(UserAccount $account, Partner $customer, array $data, array $files, ?string $idempotencyKey): PaymentConfirmation
    {
        if ($idempotencyKey !== null && ($existing = PaymentConfirmation::query()->where('customer_partner_id', $customer->id)->where('idempotency_key', $idempotencyKey)->first())) {
            return $existing;
        }
        $this->evidence->validate($files);
        [$invoiceId, $orderId, $currency, $maxAmount] = $this->target($customer, $data['invoice_id'] ?? null, $data['sales_order_id'] ?? null);
        $amount = D::exact((string) $data['amount'], D::MONEY_SCALE);
        if (D::lte($amount, '0')) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'The amount must be greater than zero.', ['field' => 'amount']);
        }
        if ($maxAmount !== null && D::gt($amount, $maxAmount)) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, "The amount is more than the outstanding balance ($maxAmount $currency).", ['field' => 'amount']);
        }
        if (($data['paid_on'] ?? '') > now()->toDateString()) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'The payment date cannot be in the future.', ['field' => 'paid_on']);
        }
        $reference = trim((string) $data['transaction_reference']);
        if ($this->payments->postedWithReference($reference) !== null) {
            throw DomainException::of(ErrorCode::DUPLICATE_RESOURCE, 'This transaction reference has already been recorded as a payment.', ['field' => 'transaction_reference']);
        }
        $open = fn () => PaymentConfirmation::query()->whereRaw('lower(transaction_reference) = ?', [mb_strtolower($reference)])
            ->whereIn('status', [S::SUBMITTED->value, S::INFO_REQUESTED->value]);
        if ((clone $open())->where('customer_partner_id', $customer->id)->exists()) {
            throw DomainException::of(ErrorCode::DUPLICATE_RESOURCE, 'You already submitted this transaction reference; it is being reviewed.', ['field' => 'transaction_reference']);
        }
        $stored = [];
        try {
            return DB::transaction(function () use ($account, $customer, $data, $files, $idempotencyKey, $invoiceId, $orderId, $currency, $amount, $reference, $open, &$stored) {
                $confirmation = PaymentConfirmation::query()->create([
                    'document_number' => $this->nextNumber(DocumentType::PAYMENT_CONFIRMATION), 'customer_partner_id' => $customer->id,
                    'submitted_by_account_id' => $account->id, 'invoice_id' => $invoiceId, 'sales_order_id' => $orderId, 'amount' => $amount,
                    'currency' => $currency, 'paid_on' => $data['paid_on'], 'method' => PaymentMethod::from((string) $data['method']),
                    'transaction_reference' => $reference, 'payer_name' => $data['payer_name'] ?? null, 'bank_name' => $data['bank_name'] ?? null,
                    'customer_note' => $data['note'] ?? null, 'status' => S::SUBMITTED, 'idempotency_key' => $idempotencyKey,
                    'duplicate_of_id' => (clone $open())->value('id'),   // another customer quoted the same reference: flagged for finance
                ]);
                foreach ($files as $file) {
                    $stored[] = $this->evidence->store(AttachableType::PAYMENT_CONFIRMATION, $confirmation->id, $file, AttachmentKind::PAYMENT_EVIDENCE, $account->id)->private_path;
                }
                $this->audit($account, 'billing.payment_confirmation.submitted', $confirmation, null, ['amount' => $amount, 'currency' => $currency,
                    'files' => count($files), 'invoice_id' => $invoiceId, 'sales_order_id' => $orderId]);
                $this->publish('billing.payment_confirmation.submitted', $confirmation, ['document_number' => $confirmation->document_number,
                    'customer_name' => $customer->name, 'amount' => $amount, 'currency' => $currency]);

                return $confirmation;
            });
        } catch (UniqueConstraintViolationException $e) {
            $this->evidence->discard($stored);

            return PaymentConfirmation::query()->where('customer_partner_id', $customer->id)->where('idempotency_key', $idempotencyKey)->first() ?? throw $e;
        } catch (\Throwable $e) {
            $this->evidence->discard($stored);
            throw $e;
        }
    }

    /** The customer answers a request for information (more evidence and/or a note). */
    public function addInformation(string $id, UserAccount $account, Partner $customer, ?string $note, array $files): PaymentConfirmation
    {
        $this->evidence->validate($files, required: false);
        if ($files === [] && trim((string) $note) === '') {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Add a note or another file.', ['field' => 'note']);
        }
        $stored = [];
        try {
            return DB::transaction(function () use ($id, $account, $customer, $note, $files, &$stored) {
                $confirmation = $this->own($id, $customer);
                $this->assertStatus($confirmation, [S::INFO_REQUESTED]);
                foreach ($files as $file) {
                    $stored[] = $this->evidence->store(AttachableType::PAYMENT_CONFIRMATION, $confirmation->id, $file, AttachmentKind::PAYMENT_EVIDENCE, $account->id)->private_path;
                }
                $notes = trim(($confirmation->customer_note ? $confirmation->customer_note."\n\n" : '').now()->toDateString().': '.trim((string) $note));
                $this->transition($confirmation, S::SUBMITTED, ['customer_note' => $notes]);
                $this->audit($account, 'billing.payment_confirmation.information_added', $confirmation, null, ['files' => count($files)]);
                $this->publish('billing.payment_confirmation.submitted', $confirmation, ['document_number' => $confirmation->document_number,
                    'customer_name' => $customer->name, 'amount' => (string) $confirmation->amount, 'currency' => $confirmation->currency]);

                return $confirmation;
            });
        } catch (\Throwable $e) {
            $this->evidence->discard($stored);
            throw $e;
        }
    }

    public function withdraw(string $id, UserAccount $account, Partner $customer): PaymentConfirmation
    {
        return DB::transaction(function () use ($id, $account, $customer) {
            $confirmation = $this->own($id, $customer);
            $this->transition($confirmation, S::WITHDRAWN);
            $this->audit($account, 'billing.payment_confirmation.withdrawn', $confirmation);

            return $confirmation;
        });
    }

    public function requestInformation(string $id, UserAccount $actor, ?string $reason, ?int $version = null): PaymentConfirmation
    {
        return $this->review($id, $actor, S::INFO_REQUESTED, $reason, $version, 'Tell the customer what is missing.');
    }

    public function reject(string $id, UserAccount $actor, ?string $reason, ?int $version = null): PaymentConfirmation
    {
        return $this->review($id, $actor, S::REJECTED, $reason, $version, 'Give the reason the customer will see.');
    }

    /**
     * Finance confirm the money arrived: records the payment (once — unique per confirmation and per
     * bank reference) and, for an invoice, allocates it.
     *
     * @param  array<string, mixed>  $data  validated against PaymentVerificationSchema
     */
    public function verify(string $id, UserAccount $actor, array $data): PaymentConfirmation
    {
        return DB::transaction(function () use ($id, $actor, $data) {
            /** @var PaymentConfirmation $confirmation */
            $confirmation = $this->lockDocument(PaymentConfirmation::class, $id);
            $this->expectVersion($confirmation, isset($data['version']) ? (int) $data['version'] : null);
            $this->assertStatus($confirmation, [S::SUBMITTED]);
            $amount = D::exact((string) ($data['amount_received'] ?? $confirmation->amount), D::MONEY_SCALE);
            $payment = $this->payments->record(['customer_partner_id' => $confirmation->customer_partner_id, 'amount' => $amount,
                'currency' => $confirmation->currency, 'received_on' => $data['received_on'] ?? $confirmation->paid_on->toDateString(),
                'method' => $confirmation->method->value, 'bank_reference' => $data['bank_reference'] ?? $confirmation->transaction_reference,
                'notes' => "Verified from customer confirmation {$confirmation->document_number}"], $actor, $confirmation->id);
            if ($confirmation->invoice_id !== null && ($data['allocate'] ?? true)) {
                $invoice = Invoice::query()->findOrFail($confirmation->invoice_id);
                if (in_array($invoice->status, [InvoiceStatus::ISSUED, InvoiceStatus::PARTIALLY_PAID], true)) {
                    $this->payments->allocate($payment->id, $actor, $invoice->id, D::min($amount, (string) $invoice->balance_due));
                }
            }
            $this->transition($confirmation, S::VERIFIED, ['payment_id' => $payment->id, 'reviewed_by' => $actor->id, 'reviewed_at' => now(),
                'review_note' => $data['comment'] ?? null]);
            $this->audit($actor, 'billing.payment_confirmation.verified', $confirmation, null, ['payment' => $payment->document_number, 'amount' => $amount]);
            $this->publish('billing.payment_confirmation.verified', $confirmation, ['document_number' => $confirmation->document_number,
                'customer_partner_id' => $confirmation->customer_partner_id, 'payment_number' => $payment->document_number]);

            return $confirmation;
        });
    }

    private function review(string $id, UserAccount $actor, S $to, ?string $reason, ?int $version, string $missing): PaymentConfirmation
    {
        if (trim((string) $reason) === '') {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, $missing, ['field' => 'reason']);
        }

        return DB::transaction(function () use ($id, $actor, $to, $reason, $version) {
            /** @var PaymentConfirmation $confirmation */
            $confirmation = $this->lockDocument(PaymentConfirmation::class, $id);
            $this->expectVersion($confirmation, $version);
            $this->transition($confirmation, $to, ['review_note' => $reason, 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);
            $this->audit($actor, 'billing.payment_confirmation.'.$to->value, $confirmation, null, null, $reason);
            $this->publish('billing.payment_confirmation.'.$to->value, $confirmation, ['document_number' => $confirmation->document_number,
                'customer_partner_id' => $confirmation->customer_partner_id]);

            return $confirmation;
        });
    }

    /**
     * What the customer may pay against: an issued, unpaid invoice of theirs, or an order of theirs
     * (advance payment). Returns [invoice id, order id, currency, maximum amount].
     *
     * @return array{0: ?string, 1: ?string, 2: string, 3: ?string}
     */
    private function target(Partner $customer, ?string $invoiceId, ?string $orderId): array
    {
        if ($invoiceId !== null) {
            $invoice = Invoice::query()->whereKey($invoiceId)->where('customer_partner_id', $customer->id)->first() ?? throw DomainException::notFound('invoice');
            if (! in_array($invoice->status, [InvoiceStatus::ISSUED, InvoiceStatus::PARTIALLY_PAID], true)) {
                throw DomainException::of(ErrorCode::INVALID_STATE_TRANSITION, 'This invoice has nothing left to pay.', ['status' => $invoice->status->value]);
            }

            return [$invoice->id, $invoice->sales_order_id, $invoice->currency, (string) $invoice->balance_due];
        }
        if ($orderId !== null) {
            $order = SalesOrder::query()->whereKey($orderId)->where('customer_partner_id', $customer->id)->first() ?? throw DomainException::notFound('order');
            if ($order->status === SalesOrderStatus::CANCELLED || D::lte((string) $order->total, '0')) {
                throw DomainException::of(ErrorCode::INVALID_STATE_TRANSITION, 'This order cannot be paid.', ['status' => $order->status->value]);
            }

            return [null, $order->id, (string) ($order->currency ?: app(PlatformConfig::class)->get('locale.currency.base')), (string) $order->total];
        }
        throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Choose the invoice or order you paid.', ['field' => 'invoice_id']);
    }

    private function own(string $id, Partner $customer): PaymentConfirmation
    {
        return PaymentConfirmation::query()->whereKey($id)->where('customer_partner_id', $customer->id)->lockForUpdate()->first()
            ?? throw DomainException::notFound('payment confirmation');
    }
}
