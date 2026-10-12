<?php

declare(strict_types=1);

namespace App\Domains\CustomerPortal\Services;

use App\Domains\Billing\Services\CreditService;
use App\Domains\Catalog\Models\Item;
use App\Domains\Fulfillment\Enums\PickSourceType;
use App\Domains\Fulfillment\Models\SalesOrder;
use App\Domains\Fulfillment\Services\SalesOrderWorkflow;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Partners\Models\Partner;
use App\Domains\Platform\Config\PlatformConfig;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Math\Decimal as D;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Customer orders (CP-1.1 sections 5.3 and 5.4): direct orders priced from list prices on the server,
 * and the customer's read model of their orders with a timeline built only from recorded fulfilment
 * events. Order processing itself is the normal sales order workflow.
 */
final class PortalOrders
{
    public const SOURCE = 'customer_portal';

    public function __construct(
        private readonly PlatformConfig $config,
        private readonly PortalCatalog $catalog,
        private readonly SalesOrderWorkflow $orders,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated against PortalOrderSchema (lines: item_id, quantity)
     */
    public function place(UserAccount $account, Partner $customer, array $data, ?string $idempotencyKey): SalesOrder
    {
        if (! $this->config->bool('portal.orders.direct_ordering')) {
            throw DomainException::of(ErrorCode::POLICY_VIOLATION, 'Online ordering is by quotation. Request a quotation instead.');
        }
        $key = $this->requireKey($idempotencyKey);
        $existing = SalesOrder::query()->where('external_source', self::SOURCE)->where('external_order_id', $customer->id.':'.$key)->first();
        if ($existing !== null) {
            return $existing;   // a retried submission returns the first order
        }
        $lines = $this->priceLines($data['lines'] ?? [], requirePrice: true);
        $currencies = array_unique(array_column($lines, 'currency'));
        if (count($currencies) > 1) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Items priced in different currencies must be ordered separately.', ['field' => 'lines']);
        }
        $total = D::sum(array_map(fn (array $l) => D::mul($l['quantity'], (string) $l['unit_price'], D::MONEY_SCALE), $lines), D::MONEY_SCALE);
        app(CreditService::class)->assertCustomerCanOrder($customer, $total);

        return $this->orders->intakeForCustomer([
            'warehouse_id' => $this->warehouseId(), 'external_source' => self::SOURCE, 'external_order_id' => $customer->id.':'.$key,
            'currency' => $currencies[0] ?? null, 'shipping_address' => $this->address($data['shipping_address'] ?? null, $customer),
            'reference' => $data['customer_reference'] ?? null, 'notes' => $data['notes'] ?? null,
            'lines' => array_map(fn (array $l) => ['item_id' => $l['item_id'], 'quantity' => $l['quantity'], 'unit_price' => $l['unit_price']], $lines),
        ], $customer, $account);
    }

    /**
     * Validate requested lines against the published catalogue and price them from list prices.
     *
     * @param  array<int, array<string, mixed>>  $requested
     * @return array<int, array{item_id: string, quantity: string, unit_price: ?string, currency: ?string, notes: ?string}>
     */
    public function priceLines(array $requested, bool $requirePrice): array
    {
        $max = $this->config->int('portal.orders.max_lines');
        if ($requested === [] || count($requested) > $max) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, $requested === [] ? 'Add at least one product.' : "At most $max products per request.", ['field' => 'lines']);
        }
        $merged = [];
        foreach (array_values($requested) as $i => $line) {
            $id = (string) ($line['item_id'] ?? '');
            $merged[$id] ??= ['item_id' => $id, 'quantity' => '0', 'notes' => $line['notes'] ?? null, 'index' => $i];
            $merged[$id]['quantity'] = D::add($merged[$id]['quantity'], (string) ($line['quantity'] ?? '0'));
        }
        $items = $this->catalog->visibleItems()->whereIn('id', array_keys($merged))->get()->keyBy('id');
        $out = [];
        foreach ($merged as $id => $line) {
            /** @var Item|null $item */
            $item = $items[$id] ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'A product in your request is no longer available online.',
                ['field' => "lines.{$line['index']}.item_id"]);
            $this->catalog->assertOrderable($item, $line['quantity'], $line['index']);
            $price = $this->config->bool('portal.catalog.show_prices') || $requirePrice ? $this->catalog->price($item) : null;
            if ($requirePrice && $price === null) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, "{$item->name} has no list price. Request a quotation for it.", ['field' => "lines.{$line['index']}.item_id"]);
            }
            $out[] = ['item_id' => $id, 'quantity' => $line['quantity'], 'unit_price' => $price, 'currency' => $price === null ? null : $this->catalog->currency($item),
                'notes' => $line['notes']];
        }

        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    public function list(Partner $customer, ?string $status = null): array
    {
        return SalesOrder::query()->where('customer_partner_id', $customer->id)->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('created_at')->limit(200)->get()
            ->map(fn (SalesOrder $o) => $this->summary($o))->all();
    }

    /** @return array<string, mixed> */
    public function show(SalesOrder $order): array
    {
        $lines = DB::table('sales_order_lines as l')->join('items as i', 'i.id', '=', 'l.item_id')->join('units_of_measure as u', 'u.id', '=', 'l.uom_id')
            ->where('l.sales_order_id', $order->id)->orderBy('l.line_no')
            ->get(['l.id', 'l.line_no', 'i.sku', 'i.name', 'u.symbol as unit', 'l.quantity', 'l.base_quantity', 'l.delivered_base_quantity', 'l.unit_price', 'l.line_total'])
            ->map(fn ($r) => (array) $r)->all();
        $shipments = DB::table('shipments as s')->leftJoin('partners as c', 'c.id', '=', 's.carrier_partner_id')->where('s.sales_order_id', $order->id)
            ->orderBy('s.created_at')->get(['s.id', 's.document_number', 's.status', 's.tracking_number', 's.packed_at', 's.handed_over_at', 's.delivered_at',
                'c.name as carrier_name', 'c.tracking_url_template'])
            ->map(function ($s) {
                $row = (array) $s;
                $row['tracking_url'] = $s->tracking_url_template && $s->tracking_number ? str_replace('{tracking_number}', rawurlencode($s->tracking_number), $s->tracking_url_template) : null;
                unset($row['tracking_url_template']);
                $row['acknowledged'] = DB::table('delivery_acknowledgements')->where('shipment_id', $s->id)->exists();

                return $row;
            })->all();
        $invoices = DB::table('invoices')->where('sales_order_id', $order->id)->where('status', '<>', 'draft')
            ->get(['id', 'document_number', 'status', 'total', 'balance_due', 'currency'])->map(fn ($r) => (array) $r)->all();

        return $this->summary($order) + [
            'lines' => $lines, 'shipments' => $shipments, 'invoices' => $invoices, 'timeline' => $this->timeline($order),
            'shipping_address' => $order->shipping_address, 'notes' => $order->notes,
            'can_cancel' => $order->status->value === 'draft',
            'can_acknowledge_direct' => in_array($order->status->value, ['partially_delivered', 'delivered'], true) && $shipments === []
                && ! DB::table('delivery_acknowledgements')->where('sales_order_id', $order->id)->whereNull('shipment_id')->exists(),
        ];
    }

    /**
     * What actually happened, in order: recorded timestamps of the order, its pick lists, shipments,
     * carrier events, postings and customer acknowledgements. Nothing is inferred from requests.
     *
     * @return array<int, array{at: string, event: string, label: string, detail: ?string}>
     */
    public function timeline(SalesOrder $order): array
    {
        $events = [['at' => $order->created_at, 'event' => 'placed', 'label' => 'Order placed', 'detail' => $order->document_number]];
        if ($order->confirmed_at !== null) {
            $events[] = ['at' => $order->confirmed_at, 'event' => 'confirmed', 'label' => 'Order confirmed', 'detail' => null];
        }
        $reserved = DB::table('stock_reservations')->where('reference_type', 'sales_order')->where('reference_id', $order->id)->min('created_at');
        if ($reserved !== null) {
            $events[] = ['at' => $reserved, 'event' => 'allocated', 'label' => 'Stock allocated', 'detail' => null];
        }
        foreach (DB::table('pick_lists')->where('source_type', PickSourceType::SALES_ORDER->value)->where('source_id', $order->id)->get(['created_at', 'completed_at']) as $p) {
            $events[] = ['at' => $p->created_at, 'event' => 'picking', 'label' => 'Picking started', 'detail' => null];
            if ($p->completed_at !== null) {
                $events[] = ['at' => $p->completed_at, 'event' => 'picked', 'label' => 'Picked', 'detail' => null];
            }
        }
        $labels = ['in_transit' => 'In transit', 'delivered' => 'Delivered', 'failed' => 'Delivery attempt failed', 'returned' => 'Returned to us', 'cancelled' => 'Shipment cancelled'];
        foreach (DB::table('shipments')->where('sales_order_id', $order->id)->get(['id', 'document_number', 'packed_at', 'handed_over_at', 'tracking_number']) as $s) {
            if ($s->packed_at !== null) {
                $events[] = ['at' => $s->packed_at, 'event' => 'packed', 'label' => 'Packed', 'detail' => $s->document_number];
            }
            if ($s->handed_over_at !== null) {
                $events[] = ['at' => $s->handed_over_at, 'event' => 'dispatched', 'label' => 'Dispatched', 'detail' => $s->tracking_number ? "Tracking {$s->tracking_number}" : $s->document_number];
            }
            foreach (DB::table('shipment_events')->where('shipment_id', $s->id)->whereIn('status', array_keys($labels))->orderBy('occurred_at')->get(['status', 'occurred_at']) as $e) {
                $events[] = ['at' => $e->occurred_at, 'event' => $e->status, 'label' => $labels[$e->status], 'detail' => $s->document_number];
            }
        }
        foreach (DB::table('stock_movements')->where('source_document_type', 'sales_order')->where('source_document_id', $order->id)
            ->where('movement_type', 'sale_delivery')->orderBy('posted_at')->get(['posted_at']) as $m) {
            $events[] = ['at' => $m->posted_at, 'event' => 'delivered', 'label' => 'Handed over to you', 'detail' => null];
        }
        foreach (DB::table('delivery_acknowledgements')->where('sales_order_id', $order->id)->get(['created_at', 'outcome']) as $a) {
            $events[] = ['at' => $a->created_at, 'event' => 'acknowledged', 'label' => $a->outcome === 'received_in_full' ? 'You confirmed receipt' : 'You reported a delivery issue', 'detail' => null];
        }
        if ($order->cancelled_at !== null) {
            $events[] = ['at' => $order->cancelled_at, 'event' => 'cancelled', 'label' => 'Order cancelled', 'detail' => $order->cancellation_reason];
        }
        usort($events, fn ($a, $b) => strcmp((string) $a['at'], (string) $b['at']));

        return array_map(fn (array $e) => ['at' => Carbon::parse($e['at'])->toIso8601String()] + $e, $events);
    }

    /** @return array<string, mixed> */
    private function summary(SalesOrder $o): array
    {
        return ['id' => $o->id, 'document_number' => $o->document_number, 'status' => $o->status->value, 'status_label' => $o->status->label(),
            'document_date' => $o->document_date?->toDateString(), 'currency' => $o->currency, 'total' => (string) $o->total, 'reference' => $o->reference,
            'from_quotation' => DB::table('quotations')->where('sales_order_id', $o->id)->value('document_number'),
            'created_at' => $o->created_at?->toIso8601String()];
    }

    private function warehouseId(): string
    {
        return (string) ($this->config->get('portal.orders.warehouse_id')
            ?? throw DomainException::of(ErrorCode::POLICY_VIOLATION, 'Online ordering is not set up yet. Please request a quotation or contact us.'));
    }

    /** @return array<string, mixed>|null */
    private function address(mixed $given, Partner $customer): ?array
    {
        if (is_array($given) && array_filter($given) !== []) {
            return array_map(fn ($v) => is_string($v) ? mb_substr(trim($v), 0, 200) : $v,
                array_intersect_key($given, array_flip(['contact', 'phone', 'line1', 'line2', 'city', 'region', 'postal_code', 'country'])));
        }
        $addresses = (array) ($customer->addresses ?? []);

        return is_array($addresses[0] ?? null) ? $addresses[0] : null;
    }

    private function requireKey(?string $key): string
    {
        if ($key === null || preg_match('/^[A-Za-z0-9_-]{8,80}$/', $key) !== 1) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Refresh the page and try again (missing request key).', ['field' => 'Idempotency-Key']);
        }

        return $key;
    }
}
