<?php

declare(strict_types=1);

namespace App\Domains\Planning\Services;

use App\Domains\Catalog\Enums\ReplenishmentMethod;
use App\Domains\Fulfillment\Enums\StockRequestStatus;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\IdentityAccess\Services\Access;
use App\Domains\Inventory\Enums\ReservationStatus;
use App\Domains\Inventory\Enums\StockCondition;
use App\Domains\Organization\Enums\InventoryLocationType;
use App\Domains\Procurement\Enums\PurchaseOrderStatus;
use App\Domains\Procurement\Services\PurchaseRequestWorkflow;
use App\Domains\Transfers\Enums\TransferStatus;
use App\Domains\Transfers\Services\TransferWorkflow;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Math\Decimal as D;
use Illuminate\Support\Facades\DB;

/**
 * Replenishment suggestions (Chapter 08 §8.1, ReplenishmentSuggestionQuery): for each active
 * reorder rule, projected = available + eligible inbound − unreserved committed outbound; below the
 * reorder point a purchase or transfer is proposed for max(reorder qty, max − projected), rounded up
 * to the multiple. Every included quantity is returned so the suggestion can be explained.
 * Unapproved requests and unconfirmed inbound are excluded.
 */
final class ReplenishmentService
{
    private const INBOUND_PO = [PurchaseOrderStatus::APPROVED, PurchaseOrderStatus::SENT, PurchaseOrderStatus::PARTIALLY_RECEIVED];

    private const OPEN_TRANSFER = [TransferStatus::APPROVED, TransferStatus::PICKING, TransferStatus::PARTIALLY_DISPATCHED, TransferStatus::DISPATCHED,
        TransferStatus::IN_TRANSIT, TransferStatus::PARTIALLY_RECEIVED];

    public function __construct(
        private readonly Access $access,
        private readonly PurchaseRequestWorkflow $purchaseRequests,
        private readonly TransferWorkflow $transfers,
    ) {}

    /**
     * @param  array{warehouse_id?: string, item_id?: string, below_only?: bool}  $filters
     * @return array<int, array<string, mixed>>
     */
    public function suggestions(UserAccount $actor, array $filters = []): array
    {
        $allowed = $this->access->warehouseIdsFor($actor, 'planning.replenishment.read');
        $rules = DB::table('item_reorder_rules as r')->join('items as i', 'i.id', '=', 'r.item_id')->leftJoin('warehouses as w', 'w.id', '=', 'r.warehouse_id')
            ->where('r.is_active', true)->whereNotNull('r.warehouse_id')->where('i.status', 'active')
            ->when(! empty($filters['warehouse_id']), fn ($q) => $q->where('r.warehouse_id', $filters['warehouse_id']))
            ->when(! empty($filters['item_id']), fn ($q) => $q->where('r.item_id', $filters['item_id']))
            ->when($allowed !== '*', fn ($q) => $q->whereIn('r.warehouse_id', (array) $allowed))
            ->orderBy('w.code')->orderBy('i.sku')
            ->get(['r.*', 'i.sku', 'i.name as item_name', 'i.base_uom_id', 'w.code as warehouse_code']);

        $out = [];
        foreach ($rules as $rule) {
            $s = $this->evaluate($rule);
            if (($filters['below_only'] ?? true) && ! $s['below_reorder_point']) {
                continue;
            }
            $out[] = $s;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function evaluate(object $rule): array
    {
        $variant = $rule->item_variant_id;
        $wh = $rule->warehouse_id;
        $available = (string) DB::table('inventory_balances as b')->join('inventory_locations as il', 'il.id', '=', 'b.inventory_location_id')
            ->where('b.item_id', $rule->item_id)->where('b.warehouse_id', $wh)->where('b.stock_condition', StockCondition::AVAILABLE->value)
            ->where('il.location_type', InventoryLocationType::PHYSICAL->value)
            ->when($variant !== null, fn ($q) => $q->where('b.item_variant_id', $variant))
            ->selectRaw('COALESCE(SUM(b.quantity_on_hand - b.quantity_reserved), 0) AS q')->value('q');
        $inboundPo = (string) DB::table('purchase_order_lines as l')->join('purchase_orders as o', 'o.id', '=', 'l.purchase_order_id')
            ->where('l.item_id', $rule->item_id)->where('o.warehouse_id', $wh)->whereIn('o.status', array_map(fn ($s) => $s->value, self::INBOUND_PO))
            ->when($variant !== null, fn ($q) => $q->where('l.item_variant_id', $variant))
            ->selectRaw('COALESCE(SUM(GREATEST(l.base_quantity - l.received_base_quantity - l.cancelled_base_quantity, 0)), 0) AS q')->value('q');
        $inboundTransfer = (string) DB::table('transfer_order_lines as l')->join('transfer_orders as t', 't.id', '=', 'l.transfer_order_id')
            ->where('l.item_id', $rule->item_id)->where('t.destination_warehouse_id', $wh)->whereIn('t.status', array_map(fn ($s) => $s->value, self::OPEN_TRANSFER))
            ->when($variant !== null, fn ($q) => $q->where('l.item_variant_id', $variant))
            ->selectRaw('COALESCE(SUM(GREATEST(l.approved_base_quantity - l.received_base_quantity - l.resolved_base_quantity - l.cancelled_base_quantity, 0)), 0) AS q')->value('q');
        $reservedFor = fn (string $table, string $refType, string $docTable, string $docKey, string $docWarehouse, array $statuses, string $outstandingSql) => (string) DB::table("$table as l")
            ->join("$docTable as d", 'd.id', '=', "l.$docKey")->where('l.item_id', $rule->item_id)->where("d.$docWarehouse", $wh)->whereIn('d.status', $statuses)
            ->when($variant !== null, fn ($q) => $q->where('l.item_variant_id', $variant))
            ->selectRaw("COALESCE(SUM(GREATEST($outstandingSql - COALESCE((SELECT SUM(r.quantity_reserved - r.quantity_consumed - r.quantity_released) FROM stock_reservations r
                WHERE r.reference_type = '$refType' AND r.reference_line_id = l.id AND r.status IN ('".ReservationStatus::ACTIVE->value."','".ReservationStatus::PARTIALLY_CONSUMED->value."')), 0), 0)), 0) AS q")
            ->value('q');
        $outboundTransfers = $reservedFor('transfer_order_lines', 'transfer_order', 'transfer_orders', 'transfer_order_id', 'source_warehouse_id',
            [TransferStatus::APPROVED->value, TransferStatus::PICKING->value, TransferStatus::PARTIALLY_DISPATCHED->value],
            'l.approved_base_quantity - l.dispatched_base_quantity - l.cancelled_base_quantity');
        $outboundRequests = $reservedFor('stock_request_lines', 'stock_request', 'stock_requests', 'stock_request_id', 'warehouse_id',
            [StockRequestStatus::APPROVED->value, StockRequestStatus::PARTIALLY_FULFILLED->value], 'l.approved_base_quantity - l.fulfilled_base_quantity');

        $projected = D::sub(D::add(D::add($available, $inboundPo), $inboundTransfer), D::add($outboundTransfers, $outboundRequests));
        $reorderPoint = D::max(D::exact((string) $rule->reorder_point), D::exact((string) $rule->safety_stock));
        $below = D::lte($projected, $reorderPoint) && ! (D::isZero($reorderPoint) && D::isZero((string) ($rule->max_qty ?? 0)));
        $proposed = '0';
        if ($below) {
            $toMax = $rule->max_qty !== null ? D::sub((string) $rule->max_qty, $projected) : '0';
            $proposed = D::max(D::exact((string) ($rule->reorder_qty ?? '0')), D::max($toMax, D::sub($reorderPoint, $projected)));
            if ($rule->multiple_of_qty !== null && D::isPositive((string) $rule->multiple_of_qty)) {
                $multiple = (string) $rule->multiple_of_qty;
                $proposed = D::mul(D::div($proposed, $multiple, 0, \App\Support\Enums\RoundingMode::CEILING), $multiple, D::QTY_SCALE);
            }
        }
        $method = ReplenishmentMethod::from($rule->replenishment_method);
        $source = null;
        if ($method === ReplenishmentMethod::TRANSFER && $rule->preferred_source_warehouse_id !== null) {
            $source = ['warehouse_id' => $rule->preferred_source_warehouse_id, 'available' => D::exact((string) DB::table('inventory_balances as b')
                ->join('inventory_locations as il', 'il.id', '=', 'b.inventory_location_id')->where('il.location_type', InventoryLocationType::PHYSICAL->value)
                ->where('b.item_id', $rule->item_id)->where('b.warehouse_id', $rule->preferred_source_warehouse_id)->where('b.stock_condition', StockCondition::AVAILABLE->value)
                ->selectRaw('COALESCE(SUM(b.quantity_on_hand - b.quantity_reserved), 0) AS q')->value('q'))];
        }

        return [
            'reorder_rule_id' => $rule->id, 'item_id' => $rule->item_id, 'item_variant_id' => $variant, 'sku' => $rule->sku, 'item_name' => $rule->item_name,
            'warehouse_id' => $wh, 'warehouse_code' => $rule->warehouse_code ?? null, 'method' => $method->value, 'supplier_id' => $rule->preferred_supplier_id, 'source' => $source,
            'available' => D::exact($available), 'inbound_purchase' => D::exact($inboundPo), 'inbound_transfer' => D::exact($inboundTransfer),
            'committed_outbound' => D::add($outboundTransfers, $outboundRequests), 'projected' => $projected,
            'reorder_point' => $reorderPoint, 'max_qty' => $rule->max_qty === null ? null : D::exact((string) $rule->max_qty),
            'below_reorder_point' => $below, 'proposed_quantity' => $proposed,
        ];
    }

    /**
     * Turn a suggestion into a draft purchase request or transfer order (the normal workflow follows).
     *
     * @return array{type: string, id: string, document_number: string}
     */
    public function convert(string $reorderRuleId, UserAccount $actor, ?string $quantity = null): array
    {
        $rule = DB::table('item_reorder_rules as r')->join('items as i', 'i.id', '=', 'r.item_id')->where('r.id', $reorderRuleId)
            ->first(['r.*', 'i.sku', 'i.name as item_name', 'i.base_uom_id']) ?? throw DomainException::notFound('Reorder rule');
        if (! $this->access->can($actor, 'planning.replenishment.convert', $rule->warehouse_id)) {
            throw DomainException::of(ErrorCode::WAREHOUSE_SCOPE_DENIED, 'You may not convert suggestions for this warehouse.');
        }
        $s = $this->evaluate($rule);
        $qty = $quantity ?? $s['proposed_quantity'];
        if (! D::isPositive($qty)) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Nothing to replenish for this rule right now.');
        }
        $line = ['item_id' => $rule->item_id, 'item_variant_id' => $rule->item_variant_id, 'quantity' => $qty, 'uom_id' => $rule->base_uom_id,
            'notes' => "Replenishment: projected {$s['projected']} ≤ reorder point {$s['reorder_point']}"];
        if ($s['method'] === ReplenishmentMethod::TRANSFER->value) {
            if ($rule->preferred_source_warehouse_id === null) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'The reorder rule has no preferred source warehouse.');
            }
            $to = $this->transfers->save(['source_warehouse_id' => $rule->preferred_source_warehouse_id, 'destination_warehouse_id' => $rule->warehouse_id,
                'reason' => 'Replenishment suggestion', 'lines' => [$line]], $actor);

            return ['type' => 'transfer_order', 'id' => $to->id, 'document_number' => $to->document_number];
        }
        $pr = $this->purchaseRequests->save(['warehouse_id' => $rule->warehouse_id, 'justification' => 'Replenishment suggestion',
            'lines' => [$line + ['suggested_supplier_id' => $rule->preferred_supplier_id]]], $actor);

        return ['type' => 'purchase_request', 'id' => $pr->id, 'document_number' => $pr->document_number];
    }
}
