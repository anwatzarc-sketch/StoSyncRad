<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Billing\Enums\InvoiceBasis;
use App\Domains\Billing\Enums\InvoiceStatus as S;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\InvoiceLine;
use App\Domains\Fulfillment\Enums\SalesOrderStatus;
use App\Domains\Fulfillment\Enums\ShipmentStatus;
use App\Domains\Fulfillment\Models\SalesOrder;
use App\Domains\Fulfillment\Models\Shipment;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Organization\Enums\DocumentType;
use App\Domains\Partners\Enums\PartnerType;
use App\Domains\Partners\Models\Partner;
use App\Domains\Platform\Config\PlatformConfig;
use App\Support\Documents\ManagesDocuments;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Math\Decimal as D;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Invoices (CP-1.1 section 5.5): the single authoritative bill. Drafts are prepared by billing staff
 * (usually from a sales order), issuing freezes them, and from then on only payment allocations move
 * the paid amount and balance. Voiding is allowed only while nothing is allocated, and is kept.
 */
final class InvoiceWorkflow
{
    use ManagesDocuments;

    private const TERMS_DAYS = ['immediate' => 0, 'cod' => 0, 'advance' => 0, 'net_15' => 15, 'net_30' => 30, 'net_60' => 60];

    public function __construct(private readonly PlatformConfig $config, private readonly TaxRates $taxes) {}

    /**
     * Staff draft (create or update) — lines: description, quantity, unit_price, optional item_id and tax_rate.
     *
     * @param  array<string, mixed>  $data  validated against InvoiceSchema
     */
    public function save(array $data, UserAccount $actor, ?string $id = null): Invoice
    {
        return DB::transaction(function () use ($data, $actor, $id) {
            if ($id === null) {
                $customer = $this->customer($data['customer_partner_id'] ?? null);
                $invoice = new Invoice;
                $invoice->forceFill(['document_number' => $this->nextNumber(DocumentType::INVOICE), 'status' => S::DRAFT, 'customer_partner_id' => $customer->id,
                    'document_date' => now()->toDateString(), 'created_by' => $actor->id,
                    'currency' => $data['currency'] ?? $customer->default_currency ?? $this->config->get('locale.currency.base'),
                    'tax_rate' => $this->taxes->default()]);
            } else {
                /** @var Invoice $invoice */
                $invoice = $this->lockDocument(Invoice::class, $id);
                $this->expectVersion($invoice, isset($data['version']) ? (int) $data['version'] : null);
                $this->assertStatus($invoice, [S::DRAFT]);
                $invoice->forceFill(['version' => $invoice->version + 1]);
            }
            $invoice->fill(array_intersect_key($data, array_flip(['reference', 'notes', 'due_date'])));
            $invoice->forceFill(['updated_by' => $actor->id])->save();
            if (array_key_exists('lines', $data)) {
                $this->writeLines($invoice, array_values((array) $data['lines']));
            }
            $this->audit($actor, $id === null ? 'billing.invoice.created' : 'billing.invoice.updated', $invoice, null, ['total' => (string) $invoice->total]);

            return $invoice->refresh();
        });
    }

    /**
     * Draft invoice for what is still to bill on a sales order: `ordered` (advance billing — ordered minus
     * already invoiced) or `delivered` (delivered minus already invoiced). Several invoices per order are
     * allowed; an order line can never be invoiced beyond its ordered quantity.
     */
    public function fromSalesOrder(string $id, UserAccount $actor, ?string $basis = null): Invoice
    {
        $basis = InvoiceBasis::tryFrom((string) ($basis ?? $this->config->get('billing.invoice.basis'))) ?? InvoiceBasis::ORDERED;

        return DB::transaction(function () use ($id, $actor, $basis) {
            $order = $this->invoiceableOrder($id);
            $invoiced = $this->invoicedQuantities($order->id);
            $lines = [];
            foreach ($this->orderLines($order->id) as $l) {
                $billable = $basis === InvoiceBasis::DELIVERED
                    ? D::div((string) $l->delivered_base_quantity, (string) $l->conversion_factor_snapshot, D::QTY_SCALE) : (string) $l->quantity;
                $quantity = D::sub($billable, $invoiced[$l->id] ?? '0');
                if (D::gt($quantity, '0')) {
                    $lines[] = $this->lineFrom($l, $quantity);
                }
            }
            if ($lines === []) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, $basis === InvoiceBasis::DELIVERED
                    ? "Nothing delivered on {$order->document_number} is left to invoice." : "Everything on {$order->document_number} is already invoiced.");
            }

            return $this->draftFor($order, $lines, $actor);
        });
    }

    /** Draft invoice for exactly what one dispatched shipment carried (one live invoice per shipment). */
    public function fromShipment(string $id, UserAccount $actor): Invoice
    {
        return DB::transaction(function () use ($id, $actor) {
            $shipment = Shipment::query()->lockForUpdate()->findOrFail($id);
            if (! in_array($shipment->status, [ShipmentStatus::HANDED_OVER, ShipmentStatus::IN_TRANSIT, ShipmentStatus::DELIVERED], true)) {
                throw DomainException::of(ErrorCode::INVALID_STATE_TRANSITION, 'Invoice a shipment once it has been handed over.', ['status' => $shipment->status->value]);
            }
            $live = Invoice::query()->where('shipment_id', $shipment->id)->where('status', '<>', S::VOID->value)->value('document_number');
            if ($live !== null) {
                throw DomainException::of(ErrorCode::DUPLICATE_RESOURCE, "Shipment {$shipment->document_number} is already invoiced ($live).");
            }
            $order = $this->invoiceableOrder($shipment->sales_order_id);
            $carried = DB::table('shipment_package_lines as l')->join('shipment_packages as p', 'p.id', '=', 'l.shipment_package_id')
                ->where('p.shipment_id', $shipment->id)->groupBy('l.sales_order_line_id')->selectRaw('l.sales_order_line_id, SUM(l.base_quantity) AS q')
                ->pluck('q', 'sales_order_line_id');
            $invoiced = $this->invoicedQuantities($order->id);
            $lines = [];
            foreach ($this->orderLines($order->id) as $l) {
                if (! isset($carried[$l->id])) {
                    continue;
                }
                $quantity = D::min(D::div((string) $carried[$l->id], (string) $l->conversion_factor_snapshot, D::QTY_SCALE),
                    D::sub((string) $l->quantity, $invoiced[$l->id] ?? '0'));
                if (D::gt($quantity, '0')) {
                    $lines[] = $this->lineFrom($l, $quantity);
                }
            }
            if ($lines === []) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, "Everything in shipment {$shipment->document_number} is already invoiced.");
            }
            $invoice = $this->draftFor($order, $lines, $actor);
            $invoice->forceFill(['shipment_id' => $shipment->id, 'reference' => trim(($order->reference ? $order->reference.' · ' : '').$shipment->document_number)])->save();

            return $invoice->refresh();
        });
    }

    public function issue(string $id, UserAccount $actor, ?int $version = null): Invoice
    {
        return DB::transaction(function () use ($id, $actor, $version) {
            /** @var Invoice $invoice */
            $invoice = $this->lockDocument(Invoice::class, $id);
            $this->expectVersion($invoice, $version);
            if (! InvoiceLine::query()->where('invoice_id', $invoice->id)->exists() || D::lte((string) $invoice->total, '0')) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'An invoice needs lines and a total above zero.');
            }
            $issueDate = now()->toDateString();
            $this->transition($invoice, S::ISSUED, ['issue_date' => $issueDate, 'issued_by' => $actor->id, 'issued_at' => now(),
                'due_date' => $invoice->due_date?->toDateString() ?? now()->addDays($this->dueDays($invoice->customer_partner_id))->toDateString()]);
            $this->audit($actor, 'billing.invoice.issued', $invoice, null, ['total' => (string) $invoice->total, 'currency' => $invoice->currency]);
            $this->publish('billing.invoice.issued', $invoice, ['document_number' => $invoice->document_number, 'customer_partner_id' => $invoice->customer_partner_id,
                'total' => (string) $invoice->total, 'currency' => $invoice->currency]);

            return $invoice;
        });
    }

    public function void(string $id, UserAccount $actor, ?string $reason, ?int $version = null): Invoice
    {
        if (trim((string) $reason) === '') {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Give the reason for voiding.', ['field' => 'reason']);
        }

        return DB::transaction(function () use ($id, $actor, $reason, $version) {
            /** @var Invoice $invoice */
            $invoice = $this->lockDocument(Invoice::class, $id);
            $this->expectVersion($invoice, $version);
            if (D::gt((string) $invoice->amount_paid, '0')) {
                throw DomainException::of(ErrorCode::POLICY_VIOLATION, 'Payments are allocated to this invoice. Reverse them (void the payment) first.');
            }
            $this->transition($invoice, S::VOID, ['voided_by' => $actor->id, 'voided_at' => now(), 'void_reason' => $reason]);
            $this->audit($actor, 'billing.invoice.voided', $invoice, null, null, $reason);

            return $invoice;
        });
    }

    /**
     * Totals are computed here only. Each line is taxed at its own rate (given, else the item's tax rate,
     * else the default); an order line can never be invoiced beyond its ordered quantity.
     */
    private function writeLines(Invoice $invoice, array $lines): void
    {
        if ($lines === []) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Add at least one line.', ['field' => 'lines']);
        }
        InvoiceLine::query()->where('invoice_id', $invoice->id)->delete();
        $subtotal = '0';
        $tax = '0';
        $rates = [];
        $perOrderLine = [];
        foreach ($lines as $i => $line) {
            if (D::lte((string) $line['quantity'], '0') || D::lt((string) $line['unit_price'], '0')) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Quantities must be positive and prices not negative.', ['field' => "lines.$i"]);
            }
            if (! empty($line['sales_order_line_id'])) {
                $perOrderLine[$line['sales_order_line_id']] = D::add($perOrderLine[$line['sales_order_line_id']] ?? '0', (string) $line['quantity']);
            }
            $rate = isset($line['tax_rate']) && $line['tax_rate'] !== null && $line['tax_rate'] !== '' ? D::exact((string) $line['tax_rate'], 4)
                : $this->taxes->forItem($line['item_id'] ?? null);
            $lineTotal = D::mul((string) $line['quantity'], (string) $line['unit_price'], D::MONEY_SCALE);
            $lineTax = D::div(D::mul($lineTotal, $rate, 8), '100', D::MONEY_SCALE);
            $subtotal = D::add($subtotal, $lineTotal, D::MONEY_SCALE);
            $tax = D::add($tax, $lineTax, D::MONEY_SCALE);
            $rates[$rate] = true;
            InvoiceLine::query()->create(['invoice_id' => $invoice->id, 'line_no' => $i + 1, 'item_id' => $line['item_id'] ?? null,
                'sales_order_line_id' => $line['sales_order_line_id'] ?? null, 'description' => mb_substr((string) $line['description'], 0, 300),
                'quantity' => $line['quantity'], 'unit_price' => $line['unit_price'], 'line_total' => $lineTotal, 'tax_rate' => $rate, 'tax_amount' => $lineTax]);
        }
        if ($perOrderLine !== []) {
            $ordered = DB::table('sales_order_lines')->whereIn('id', array_keys($perOrderLine))->pluck('quantity', 'id');
            $elsewhere = DB::table('invoice_lines as l')->join('invoices as v', 'v.id', '=', 'l.invoice_id')->whereIn('l.sales_order_line_id', array_keys($perOrderLine))
                ->where('v.status', '<>', S::VOID->value)->where('v.id', '<>', $invoice->id)->groupBy('l.sales_order_line_id')
                ->selectRaw('l.sales_order_line_id, SUM(l.quantity) AS q')->pluck('q', 'sales_order_line_id');
            foreach ($perOrderLine as $orderLineId => $quantity) {
                if (D::gt(D::add($quantity, (string) ($elsewhere[$orderLineId] ?? '0')), (string) ($ordered[$orderLineId] ?? '0'))) {
                    throw DomainException::of(ErrorCode::QUANTITY_EXCEEDS_OUTSTANDING, 'An order line would be invoiced for more than was ordered.',
                        ['field' => 'lines', 'sales_order_line_id' => $orderLineId]);
                }
            }
        }
        $total = D::add($subtotal, $tax, D::MONEY_SCALE);
        $invoice->forceFill(['subtotal' => $subtotal, 'tax_amount' => $tax, 'total' => $total, 'amount_paid' => '0', 'balance_due' => $total,
            // One rate across the lines, else the effective (blended) rate.
            'tax_rate' => count($rates) === 1 ? (string) array_key_first($rates) : (D::isPositive($subtotal) ? D::div(D::mul($tax, '100', 8), $subtotal, 4) : '0')])->save();
    }

    private function invoiceableOrder(string $id): SalesOrder
    {
        $order = SalesOrder::query()->lockForUpdate()->findOrFail($id);
        if (in_array($order->status, [SalesOrderStatus::DRAFT, SalesOrderStatus::CANCELLED], true)) {
            throw DomainException::of(ErrorCode::INVALID_STATE_TRANSITION, 'Invoice an order once it is confirmed.', ['status' => $order->status->value]);
        }

        return $order;
    }

    /** @return Collection<int, object> */
    private function orderLines(string $orderId)
    {
        $lines = DB::table('sales_order_lines as l')->join('items as i', 'i.id', '=', 'l.item_id')->where('l.sales_order_id', $orderId)->orderBy('l.line_no')
            ->get(['l.id', 'l.item_id', 'l.quantity', 'l.delivered_base_quantity', 'l.conversion_factor_snapshot', 'l.unit_price', 'i.sku', 'i.name']);
        if ($lines->contains(fn ($l) => $l->unit_price === null)) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Every order line needs a price before it can be invoiced.');
        }

        return $lines;
    }

    /** Quantity already invoiced per order line (order unit), void invoices excluded. @return array<string, string> */
    private function invoicedQuantities(string $orderId): array
    {
        return DB::table('invoice_lines as l')->join('invoices as v', 'v.id', '=', 'l.invoice_id')->where('v.sales_order_id', $orderId)
            ->where('v.status', '<>', S::VOID->value)->whereNotNull('l.sales_order_line_id')->groupBy('l.sales_order_line_id')
            ->selectRaw('l.sales_order_line_id, SUM(l.quantity) AS q')->pluck('q', 'sales_order_line_id')->map(fn ($q) => (string) $q)->all();
    }

    /** @return array<string, mixed> */
    private function lineFrom(object $l, string $quantity): array
    {
        return ['item_id' => $l->item_id, 'sales_order_line_id' => $l->id, 'description' => "{$l->sku} — {$l->name}", 'quantity' => $quantity,
            'unit_price' => (string) $l->unit_price];
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    private function draftFor(SalesOrder $order, array $lines, UserAccount $actor): Invoice
    {
        $invoice = $this->save(['customer_partner_id' => $order->customer_partner_id, 'currency' => $order->currency, 'reference' => $order->reference,
            'lines' => $lines], $actor);
        $invoice->forceFill(['sales_order_id' => $order->id])->save();

        return $invoice->refresh();
    }

    private function customer(?string $id): Partner
    {
        $partner = Partner::query()->find($id) ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Choose the customer.', ['field' => 'customer_partner_id']);
        if (! $partner->hasType(PartnerType::CUSTOMER)) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, "{$partner->name} is not a customer.", ['field' => 'customer_partner_id']);
        }

        return $partner;
    }

    private function dueDays(string $partnerId): int
    {
        $code = DB::table('partners as p')->join('lookup_values as v', 'v.id', '=', 'p.payment_terms_lookup_value_id')->where('p.id', $partnerId)->value('v.code');

        return self::TERMS_DAYS[$code] ?? $this->config->int('billing.invoice.default_due_days');
    }
}
