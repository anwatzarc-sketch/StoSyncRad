<?php

declare(strict_types=1);

namespace App\Domains\Sales\Services;

use App\Domains\Billing\Services\CreditService;
use App\Domains\CustomerPortal\Services\PortalCatalog;
use App\Domains\CustomerPortal\Services\PortalOrders;
use App\Domains\Fulfillment\Models\SalesOrder;
use App\Domains\Fulfillment\Services\SalesOrderWorkflow;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Organization\Enums\DocumentType;
use App\Domains\Organization\Models\Warehouse;
use App\Domains\Partners\Models\Partner;
use App\Domains\Platform\Config\PlatformConfig;
use App\Domains\Sales\Enums\QuotationStatus as S;
use App\Domains\Sales\Models\Quotation;
use App\Domains\Sales\Models\QuotationLine;
use App\Support\Documents\ManagesDocuments;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Math\Decimal as D;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Quotations (CP-1.1 section 5.2), the smallest extension the existing sales flow needed: the customer
 * requests a quotation, staff price and send it, the customer accepts (once, while valid), declines or
 * asks for a revision. Acceptance creates the sales order with the quoted prices through the normal
 * sales order workflow; retries return that same order.
 */
final class QuotationWorkflow
{
    use ManagesDocuments;

    public function __construct(
        private readonly PlatformConfig $config,
        private readonly PortalOrders $portalOrders,
        private readonly PortalCatalog $catalog,
        private readonly SalesOrderWorkflow $orders,
    ) {}

    /** @param  array<string, mixed>  $data  validated against QuotationRequestSchema */
    public function request(UserAccount $account, Partner $customer, array $data, ?string $idempotencyKey): Quotation
    {
        if ($idempotencyKey !== null && ($existing = Quotation::query()->where('customer_partner_id', $customer->id)->where('idempotency_key', $idempotencyKey)->first())) {
            return $existing;
        }
        $lines = $this->portalOrders->priceLines($data['lines'] ?? [], requirePrice: false);
        try {
            return DB::transaction(function () use ($account, $customer, $data, $idempotencyKey, $lines) {
                $quotation = new Quotation;
                $quotation->forceFill([
                    'document_number' => $this->nextNumber(DocumentType::QUOTATION), 'document_date' => now()->toDateString(), 'status' => S::REQUESTED,
                    'customer_partner_id' => $customer->id, 'requested_by_account_id' => $account->id, 'created_by' => $account->id,
                    'customer_note' => $data['note'] ?? null, 'reference' => $data['customer_reference'] ?? null, 'idempotency_key' => $idempotencyKey,
                ])->save();
                $items = DB::table('items')->whereIn('id', array_column($lines, 'item_id'))->pluck('base_uom_id', 'id');
                foreach ($lines as $i => $line) {
                    QuotationLine::query()->create(['quotation_id' => $quotation->id, 'line_no' => $i + 1, 'item_id' => $line['item_id'], 'uom_id' => $items[$line['item_id']],
                        'quantity' => $line['quantity'], 'notes' => $line['notes']]);
                }
                $this->audit($account, 'sales.quotation.requested', $quotation, null, ['lines' => count($lines)]);
                $this->publish('sales.quotation.requested', $quotation, ['document_number' => $quotation->document_number, 'customer_name' => $customer->name]);

                return $quotation;
            });
        } catch (UniqueConstraintViolationException $e) {
            return Quotation::query()->where('customer_partner_id', $customer->id)->where('idempotency_key', $idempotencyKey)->first() ?? throw $e;
        }
    }

    /**
     * Staff price the request and send it to the customer.
     *
     * @param  array<string, mixed>  $data  validated against QuotationPriceSchema
     */
    public function price(string $id, UserAccount $actor, array $data): Quotation
    {
        return DB::transaction(function () use ($id, $actor, $data) {
            /** @var Quotation $quotation */
            $quotation = $this->lockDocument(Quotation::class, $id);
            $this->expectVersion($quotation, isset($data['version']) ? (int) $data['version'] : null);
            $this->assertStatus($quotation, [S::REQUESTED, S::REVISION_REQUESTED, S::EXPIRED]);
            $warehouse = Warehouse::query()->findOrFail($data['warehouse_id'] ?? $this->config->get('portal.orders.warehouse_id')
                ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Choose the warehouse that will fulfil this quotation.', ['field' => 'warehouse_id']));
            $warehouse->assertOperational();
            $prices = collect((array) ($data['lines'] ?? []))->keyBy('line_id');
            $total = '0';
            foreach (QuotationLine::query()->where('quotation_id', $quotation->id)->orderBy('line_no')->get() as $line) {
                $price = $prices[$line->id]['unit_price'] ?? $line->unit_price
                    ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED, "Enter a price for line {$line->line_no}.", ['field' => 'lines', 'line_id' => $line->id]);
                if (D::lt((string) $price, '0')) {
                    throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Prices cannot be negative.', ['field' => 'lines', 'line_id' => $line->id]);
                }
                $lineTotal = D::mul((string) $line->quantity, (string) $price, D::MONEY_SCALE);
                $line->forceFill(['unit_price' => $price, 'line_total' => $lineTotal])->save();
                $total = D::add($total, $lineTotal, D::MONEY_SCALE);
            }
            $validUntil = $data['valid_until'] ?? now()->addDays($this->config->int('portal.quotation.validity_days'))->toDateString();
            if ($validUntil < now()->toDateString()) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'The validity date cannot be in the past.', ['field' => 'valid_until']);
            }
            $this->transition($quotation, S::SENT, [
                'warehouse_id' => $warehouse->id, 'branch_id' => $warehouse->branch_id, 'currency' => $data['currency'] ?? $this->config->get('locale.currency.base'),
                'subtotal' => $total, 'total' => $total, 'valid_until' => $validUntil, 'staff_note' => $data['comment'] ?? $quotation->staff_note,
                'revision' => $quotation->revision + 1, 'sent_by' => $actor->id, 'sent_at' => now(), 'updated_by' => $actor->id,
            ]);
            $this->audit($actor, 'sales.quotation.sent', $quotation, null, ['total' => $total, 'valid_until' => $validUntil, 'revision' => $quotation->revision]);
            $this->publish('sales.quotation.sent', $quotation, ['document_number' => $quotation->document_number, 'customer_partner_id' => $quotation->customer_partner_id]);

            return $quotation;
        });
    }

    /** Customer acceptance: creates the sales order once. A retry or double click returns the same order. */
    public function accept(string $id, UserAccount $account, Partner $customer): SalesOrder
    {
        // An out-of-date quotation is marked expired (kept) before the acceptance is refused.
        $expired = DB::transaction(function () use ($id, $customer) {
            $quotation = $this->ownLocked($id, $customer);
            if ($quotation->status !== S::SENT || $quotation->valid_until === null || ! $quotation->valid_until->lt(now()->startOfDay())) {
                return null;
            }
            $this->transition($quotation, S::EXPIRED);
            $this->audit(null, 'sales.quotation.expired', $quotation);
            $this->publish('sales.quotation.expired', $quotation, ['document_number' => $quotation->document_number,
                'customer_partner_id' => $quotation->customer_partner_id]);

            return $quotation->valid_until->toDateString();
        });
        if ($expired !== null) {
            throw DomainException::of(ErrorCode::POLICY_VIOLATION, 'This quotation has expired. Ask us for a new one.', ['valid_until' => $expired]);
        }

        return DB::transaction(function () use ($id, $account, $customer) {
            $quotation = $this->ownLocked($id, $customer);
            if ($quotation->status === S::ACCEPTED && $quotation->sales_order_id !== null) {
                return SalesOrder::query()->findOrFail($quotation->sales_order_id);
            }
            $this->assertStatus($quotation, [S::SENT]);
            app(CreditService::class)->assertCustomerCanOrder($customer, (string) $quotation->total);
            $lines = QuotationLine::query()->where('quotation_id', $quotation->id)->orderBy('line_no')->get();
            $order = $this->orders->intakeForCustomer([
                'warehouse_id' => $quotation->warehouse_id, 'external_source' => 'quotation', 'external_order_id' => $quotation->id,
                'currency' => $quotation->currency, 'reference' => $quotation->reference ?: $quotation->document_number,
                'notes' => "From quotation {$quotation->document_number}",
                'lines' => $lines->map(fn (QuotationLine $l) => ['item_id' => $l->item_id, 'quantity' => (string) $l->quantity, 'unit_price' => (string) $l->unit_price, 'notes' => $l->notes])->all(),
            ], $customer, $account);
            $this->transition($quotation, S::ACCEPTED, ['sales_order_id' => $order->id, 'responded_by_account_id' => $account->id, 'responded_at' => now()]);
            $this->audit($account, 'sales.quotation.accepted', $quotation, null, ['sales_order' => $order->document_number]);
            $this->publish('sales.quotation.accepted', $quotation, ['document_number' => $quotation->document_number, 'sales_order_id' => $order->id,
                'sales_order_number' => $order->document_number, 'customer_name' => $customer->name]);

            return $order;
        });
    }

    /**
     * Nightly: sent quotations past their validity date become expired (kept; staff may re-price them).
     * Idempotent — already expired quotations are not touched.
     */
    public function expireDue(): int
    {
        $expired = 0;
        $ids = Quotation::query()->where('status', S::SENT->value)->whereNotNull('valid_until')->where('valid_until', '<', now()->toDateString())->pluck('id');
        foreach ($ids as $id) {
            DB::transaction(function () use ($id, &$expired) {
                /** @var Quotation $quotation */
                $quotation = $this->lockDocument(Quotation::class, $id);
                if ($quotation->status !== S::SENT) {
                    return;
                }
                $this->transition($quotation, S::EXPIRED);
                $this->audit(null, 'sales.quotation.expired', $quotation, null, ['valid_until' => $quotation->valid_until?->toDateString()]);
                $this->publish('sales.quotation.expired', $quotation, ['document_number' => $quotation->document_number,
                    'customer_partner_id' => $quotation->customer_partner_id]);
                $expired++;
            });
        }

        return $expired;
    }

    public function decline(string $id, UserAccount $account, Partner $customer, ?string $reason): Quotation
    {
        return $this->respond($id, $account, $customer, S::DECLINED, $reason);
    }

    public function requestRevision(string $id, UserAccount $account, Partner $customer, ?string $reason): Quotation
    {
        if (trim((string) $reason) === '') {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Tell us what to change.', ['field' => 'reason']);
        }

        return $this->respond($id, $account, $customer, S::REVISION_REQUESTED, $reason);
    }

    public function cancel(string $id, UserAccount $actor, ?string $reason, ?int $version = null): Quotation
    {
        return DB::transaction(function () use ($id, $actor, $reason, $version) {
            /** @var Quotation $quotation */
            $quotation = $this->lockDocument(Quotation::class, $id);
            $this->expectVersion($quotation, $version);
            $this->transition($quotation, S::CANCELLED, ['cancelled_by' => $actor->id, 'cancelled_at' => now(), 'cancellation_reason' => $reason]);
            $this->audit($actor, 'sales.quotation.cancelled', $quotation, null, null, $reason);

            return $quotation;
        });
    }

    private function respond(string $id, UserAccount $account, Partner $customer, S $to, ?string $reason): Quotation
    {
        return DB::transaction(function () use ($id, $account, $customer, $to, $reason) {
            $quotation = $this->ownLocked($id, $customer);
            $this->assertStatus($quotation, [S::SENT]);
            $this->transition($quotation, $to, ['revision_note' => $reason, 'responded_by_account_id' => $account->id, 'responded_at' => now()]);
            $this->audit($account, 'sales.quotation.'.$to->value, $quotation, null, null, $reason);
            $this->publish('sales.quotation.'.$to->value, $quotation, ['document_number' => $quotation->document_number, 'customer_name' => $customer->name]);

            return $quotation;
        });
    }

    private function ownLocked(string $id, Partner $customer): Quotation
    {
        return Quotation::query()->whereKey($id)->where('customer_partner_id', $customer->id)->lockForUpdate()->first()
            ?? throw DomainException::notFound('quotation');
    }
}
