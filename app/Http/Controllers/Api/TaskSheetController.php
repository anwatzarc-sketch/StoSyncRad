<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\Catalog\Models\Item;
use App\Domains\IdentityAccess\Services\Access;
use App\Domains\Inventory\Enums\ReservationStatus;
use App\Domains\Inventory\Services\PickingPlanner;
use App\Domains\Organization\Models\Warehouse;
use App\Domains\Stocktaking\Models\Stocktake;
use App\Domains\Transfers\Models\TransferOrder;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Math\Decimal as D;
use App\Support\Sentinel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Warehouse-floor task sheets (Chapter 12 §12.6, §12.8): one denormalized read per task — item,
 * location and lot labels, outstanding quantities, FEFO suggestions — so scan-first screens never ask
 * for ids. Read-only; every posting still goes through the document actions. Scope is checked
 * against the warehouse where the work happens.
 */
final class TaskSheetController extends ApiController
{
    public function __construct(private readonly Access $access) {}

    /** Lines still to dispatch, with pick suggestions in the source warehouse (removal strategy per category). */
    public function dispatchSheet(Request $request, PickingPlanner $planner, string $id): JsonResponse
    {
        $to = TransferOrder::query()->findOrFail($id);
        $this->assertScope($request, 'transfers.transfer.dispatch', $to->source_warehouse_id);
        $lines = DB::table('transfer_order_lines as l')->join('items as i', 'i.id', '=', 'l.item_id')->leftJoin('inventory_lots as lot', 'lot.id', '=', 'l.inventory_lot_id')
            ->where('l.transfer_order_id', $to->id)->orderBy('l.line_no')
            ->get(['l.id', 'l.line_no', 'l.item_id', 'l.item_variant_id', 'l.inventory_lot_id', 'lot.lot_number as requested_lot', 'i.sku', 'i.name', 'i.tracking_mode',
                'l.approved_base_quantity', 'l.dispatched_base_quantity', 'l.cancelled_base_quantity']);
        $out = [];
        foreach ($lines as $l) {
            $outstanding = D::sub(D::sub((string) ($l->approved_base_quantity ?? '0'), (string) $l->dispatched_base_quantity), (string) $l->cancelled_base_quantity);
            $out[] = (array) $l + ['outstanding' => $outstanding, 'suggestions' => D::isPositive($outstanding)
                ? $this->suggestions($planner, $to, $l->id, $l->item_id, $l->item_variant_id, $l->inventory_lot_id, $outstanding) : []];
        }

        return response()->json(['data' => ['transfer' => $this->header($to), 'lines' => $out]]);
    }

    /** Dispatched lines still in transit to the destination, with the lot/serial the receiver must verify. */
    public function receiptSheet(Request $request, string $id): JsonResponse
    {
        $to = TransferOrder::query()->findOrFail($id);
        $this->assertScope($request, 'transfers.transfer.receive', $to->destination_warehouse_id);
        $lines = DB::table('transfer_dispatch_lines as dl')->join('transfer_dispatches as d', 'd.id', '=', 'dl.transfer_dispatch_id')
            ->join('items as i', 'i.id', '=', 'dl.item_id')->leftJoin('inventory_lots as lot', 'lot.id', '=', 'dl.inventory_lot_id')
            ->leftJoin('item_serials as s', 's.id', '=', 'dl.item_serial_id')
            ->where('d.transfer_order_id', $to->id)
            ->whereRaw('dl.base_quantity - dl.received_base_quantity - dl.resolved_base_quantity > 0')
            ->orderBy('d.dispatched_at')->orderBy('i.sku')
            ->get(['dl.id', 'd.document_number as dispatch_number', 'd.dispatched_at', 'd.expected_arrival', 'dl.item_id', 'i.sku', 'i.name', 'i.tracking_mode',
                'lot.lot_number', 'lot.expiry_date', 's.serial_number', 'dl.base_quantity', 'dl.received_base_quantity', 'dl.resolved_base_quantity']);

        return response()->json(['data' => ['transfer' => $this->header($to), 'lines' => $lines->map(fn ($l) => (array) $l + [
            'outstanding' => D::sub(D::sub((string) $l->base_quantity, (string) $l->received_base_quantity), (string) $l->resolved_base_quantity),
        ])->all()]]);
    }

    /** Count sheet in walking order; book quantities only for those allowed to see them (blind counts). */
    public function countSheet(Request $request, string $id): JsonResponse
    {
        $st = Stocktake::query()->findOrFail($id);
        $actor = $this->actor($request);
        if (! $this->access->can($actor, 'stocktaking.stocktake.count', $st->warehouse_id) && ! $this->access->can($actor, 'stocktaking.stocktake.recount', $st->warehouse_id)) {
            throw DomainException::of(ErrorCode::WAREHOUSE_SCOPE_DENIED, 'You are not authorized to count in this warehouse.');
        }
        $showBook = ! $st->is_blind || $this->access->can($actor, 'stocktaking.stocktake.view_book_qty', $st->warehouse_id);
        $lines = DB::table('stocktake_lines as l')->join('items as i', 'i.id', '=', 'l.item_id')
            ->leftJoin('storage_locations as sl', 'sl.id', '=', 'l.storage_location_id')->leftJoin('inventory_lots as lot', 'lot.id', '=', 'l.inventory_lot_id')
            ->where('l.stocktake_id', $st->id)->orderBy('sl.full_path')->orderBy('i.sku')->orderBy('l.line_no')
            ->get(['l.id', 'l.line_no', 'sl.full_path as location_path', 'sl.barcode as location_barcode', 'l.item_id', 'i.sku', 'i.name', 'lot.lot_number',
                'lot.expiry_date', 'l.stock_condition', 'l.snapshot_quantity', 'l.counted_quantity', 'l.recount_quantity', 'l.count_status', 'l.is_unexpected', 'l.notes']);

        return response()->json(['data' => [
            'stocktake' => ['id' => $st->id, 'document_number' => $st->document_number, 'status' => $st->status->value, 'is_blind' => (bool) $st->is_blind,
                'warehouse_id' => $st->warehouse_id, 'version' => $st->version, 'instructions' => $st->instructions, 'show_book_quantity' => $showBook],
            'lines' => $lines->map(function ($l) use ($showBook) {
                $row = (array) $l;
                if (! $showBook) {
                    unset($row['snapshot_quantity']);
                }

                return $row;
            })->all(),
        ]]);
    }

    /** Pick list in walking order with labels. */
    public function pickSheet(Request $request, string $id): JsonResponse
    {
        $list = DB::table('pick_lists')->where('id', $id)->first() ?? throw DomainException::notFound('Pick list');
        $actor = $this->actor($request);
        if (! $this->access->can($actor, 'fulfillment.pick_list.pick', $list->warehouse_id) && ! $this->access->can($actor, 'transfers.transfer.pick', $list->warehouse_id)) {
            throw DomainException::of(ErrorCode::WAREHOUSE_SCOPE_DENIED, 'You are not authorized to pick in this warehouse.');
        }
        $lines = DB::table('pick_list_lines as l')->join('items as i', 'i.id', '=', 'l.item_id')
            ->leftJoin('storage_locations as sl', 'sl.id', '=', 'l.storage_location_id')->leftJoin('inventory_lots as lot', 'lot.id', '=', 'l.inventory_lot_id')
            ->leftJoin('item_serials as s', 's.id', '=', 'l.item_serial_id')
            ->where('l.pick_list_id', $list->id)->orderBy('l.sort_order')->orderBy('l.line_no')
            ->get(['l.id', 'l.line_no', 'sl.full_path as location_path', 'sl.barcode as location_barcode', 'i.sku', 'i.name', 'lot.lot_number', 'lot.expiry_date',
                's.serial_number', 'l.suggested_base_quantity', 'l.picked_base_quantity', 'l.status', 'l.short_pick_reason']);

        return response()->json(['data' => [
            'pick_list' => ['id' => $list->id, 'document_number' => $list->document_number, 'status' => $list->status, 'source_type' => $list->source_type,
                'source_id' => $list->source_id, 'version' => $list->version],
            'lines' => $lines->map(fn ($l) => (array) $l)->all(),
        ]]);
    }

    /**
     * Positions to pick for one transfer line: first what approval reserved for this line (the
     * dispatch consumes those reservations), then free stock in removal-strategy order.
     *
     * @return array<int, array<string, mixed>>
     */
    private function suggestions(PickingPlanner $planner, TransferOrder $to, string $lineId, string $itemId, ?string $variantId, ?string $lotId, string $outstanding): array
    {
        $remaining = $outstanding;
        $out = [];
        $reserved = DB::table('stock_reservations as r')->join('inventory_locations as il', 'il.id', '=', 'r.inventory_location_id')
            ->join('storage_locations as sl', 'sl.id', '=', 'il.storage_location_id')->leftJoin('inventory_lots as lot', 'lot.id', '=', 'r.inventory_lot_id')
            ->where('r.reference_line_id', $lineId)->whereIn('r.status', [ReservationStatus::ACTIVE->value, ReservationStatus::PARTIALLY_CONSUMED->value])
            ->orderByRaw('lot.expiry_date ASC NULLS LAST')->orderBy('sl.full_path')
            ->get(['sl.id as storage_location_id', 'sl.full_path as location_path', 'r.inventory_lot_id', 'lot.lot_number', 'lot.expiry_date',
                DB::raw('r.quantity_reserved - r.quantity_consumed - r.quantity_released AS open')]);
        foreach ($reserved as $r) {
            if (! D::isPositive($remaining) || ! D::isPositive((string) $r->open)) {
                continue;
            }
            $take = D::min(D::exact((string) $r->open), $remaining);
            $out[] = ['storage_location_id' => $r->storage_location_id, 'location_path' => $r->location_path, 'lot_id' => Sentinel::toNull($r->inventory_lot_id),
                'lot_number' => $r->lot_number, 'expiry_date' => $r->expiry_date, 'available' => D::exact((string) $r->open), 'take' => $take, 'reserved' => true];
            $remaining = D::sub($remaining, $take);
        }
        if (D::isPositive($remaining)) {
            foreach ($planner->plan(Item::query()->findOrFail($itemId), $variantId, $to->source_warehouse_id, $remaining, $lotId) as $p) {
                $out[] = ['storage_location_id' => $p['storage_location_id'], 'location_path' => $p['location_path'], 'lot_id' => $p['lot_id'], 'lot_number' => $p['lot_number'],
                    'expiry_date' => $p['expiry_date'], 'available' => $p['available'], 'take' => $p['take'], 'reserved' => false];
            }
        }

        return $out;
    }

    private function header(TransferOrder $to): array
    {
        $codes = Warehouse::query()->whereIn('id', [$to->source_warehouse_id, $to->destination_warehouse_id])->pluck('code', 'id');

        return ['id' => $to->id, 'document_number' => $to->document_number, 'status' => $to->status->value, 'version' => $to->version,
            'source_warehouse' => $codes[$to->source_warehouse_id] ?? null, 'destination_warehouse' => $codes[$to->destination_warehouse_id] ?? null,
            'source_warehouse_id' => $to->source_warehouse_id, 'destination_warehouse_id' => $to->destination_warehouse_id];
    }

    private function assertScope(Request $request, string $permission, string $warehouseId): void
    {
        if (! $this->access->can($this->actor($request), $permission, $warehouseId)) {
            throw DomainException::of(ErrorCode::WAREHOUSE_SCOPE_DENIED, 'You are not authorized for this operation in this warehouse.', ['permission' => $permission]);
        }
    }
}
