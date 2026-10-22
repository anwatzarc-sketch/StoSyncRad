<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\Catalog\Models\Item;
use App\Domains\IdentityAccess\Services\Access;
use App\Domains\Inventory\Services\PickingPlanner;
use App\Domains\Planning\Services\DemandService;
use App\Domains\Planning\Services\ReplenishmentService;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Math\Decimal as D;
use App\Support\Sentinel;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Operational stock queries for screens and handhelds (Chapter 12): live availability by position,
 * item stock across warehouses, stock card with running balance, scan resolution, FEFO/FIFO pick
 * suggestions, lot/serial trace, demand and replenishment. Always warehouse-scoped.
 */
final class StockQueryController extends ApiController
{
    public function __construct(private readonly Access $access) {}

    public function availability(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $q = DB::table('inventory_balances as b')->join('items as i', 'i.id', '=', 'b.item_id')
            ->join('inventory_locations as il', 'il.id', '=', 'b.inventory_location_id')->leftJoin('storage_locations as sl', 'sl.id', '=', 'il.storage_location_id')
            ->leftJoin('inventory_lots as l', 'l.id', '=', 'b.inventory_lot_id')->leftJoin('warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->where('il.location_type', 'physical')->where(fn ($w) => $w->where('b.quantity_on_hand', '<>', 0)->orWhere('b.quantity_reserved', '<>', 0));
        $this->scope($q, $actor, 'inventory.stock.read', 'b.warehouse_id');
        foreach (['item_id' => 'b.item_id', 'warehouse_id' => 'b.warehouse_id', 'storage_location_id' => 'il.storage_location_id', 'lot_id' => 'b.inventory_lot_id'] as $param => $col) {
            if ($request->filled($param)) {
                $q->where($col, $this->uuid($request, $param));
            }
        }
        if ($request->filled('condition')) {
            $q->where('b.stock_condition', (string) $request->query('condition'));
        }
        $rows = $q->orderBy('i.sku')->orderBy('w.code')->orderBy('sl.full_path')->limit(2000)->get([
            'b.id', 'b.item_id', 'i.sku', 'i.name as item_name', 'b.warehouse_id', 'w.code as warehouse_code', 'il.storage_location_id', 'sl.full_path as location_path',
            'b.inventory_lot_id', 'l.lot_number', 'l.expiry_date', 'b.stock_condition', 'b.quantity_on_hand', 'b.quantity_reserved',
            DB::raw('(b.quantity_on_hand - b.quantity_reserved) AS quantity_available'), 'b.is_frozen',
        ])->map(fn ($r) => ['inventory_lot_id' => Sentinel::toNull($r->inventory_lot_id), 'is_frozen' => (bool) $r->is_frozen] + (array) $r)->all();

        return response()->json(['data' => $rows]);
    }

    public function item(Request $request, string $itemId): JsonResponse
    {
        $actor = $this->actor($request);
        $item = Item::query()->findOrFail($itemId);
        $q = DB::table('inventory_balances as b')->join('inventory_locations as il', 'il.id', '=', 'b.inventory_location_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'b.warehouse_id')->where('b.item_id', $item->id)->whereIn('il.location_type', ['physical', 'in_transit']);
        $this->scope($q, $actor, 'inventory.stock.read', 'b.warehouse_id');
        $rows = $q->groupBy('b.warehouse_id', 'w.code', 'w.name', 'il.location_type', 'b.stock_condition')
            ->orderBy('w.code')->get(['b.warehouse_id', 'w.code as warehouse_code', 'w.name as warehouse_name', 'il.location_type', 'b.stock_condition',
                DB::raw('SUM(b.quantity_on_hand) AS on_hand'), DB::raw('SUM(b.quantity_reserved) AS reserved'), DB::raw('SUM(b.quantity_on_hand - b.quantity_reserved) AS available')]);

        return response()->json(['data' => ['item' => $this->presenter()->model($item), 'positions' => $rows->map(fn ($r) => (array) $r)->all()]]);
    }

    /** Stock card: every movement touching the item in a warehouse, with a running balance. */
    public function card(Request $request, string $itemId): JsonResponse
    {
        $actor = $this->actor($request);
        $warehouseId = $this->uuid($request, 'warehouse_id');
        if (! $this->access->can($actor, 'inventory.movement.read', $warehouseId)) {
            throw DomainException::permissionDenied('inventory.movement.read', $warehouseId);
        }
        $from = (string) $request->query('from', now()->subDays(30)->toDateString());
        $to = (string) $request->query('to', now()->toDateString());
        $signed = "CASE WHEN dil.warehouse_id = ? AND dil.location_type = 'physical' THEN ml.quantity ELSE 0 END
                 - CASE WHEN sil.warehouse_id = ? AND sil.location_type = 'physical' THEN ml.quantity ELSE 0 END";
        $base = fn () => DB::table('stock_movement_lines as ml')->join('stock_movements as m', 'm.id', '=', 'ml.stock_movement_id')
            ->join('inventory_locations as sil', 'sil.id', '=', 'ml.source_inventory_location_id')->join('inventory_locations as dil', 'dil.id', '=', 'ml.destination_inventory_location_id')
            ->where('ml.item_id', $itemId)->where(fn ($w) => $w->where('ml.source_warehouse_id', $warehouseId)->orWhere('ml.destination_warehouse_id', $warehouseId));
        $opening = (string) $base()->where('m.business_date', '<', $from)->selectRaw("COALESCE(SUM($signed), 0) AS q", [$warehouseId, $warehouseId])->value('q');
        $lines = $base()->whereBetween('m.business_date', [$from, $to])->leftJoin('inventory_lots as l', 'l.id', '=', 'ml.inventory_lot_id')
            ->orderBy('m.posted_at')->orderBy('ml.line_no')->limit(5000)
            ->selectRaw("m.posted_at, m.business_date, m.movement_number, m.movement_type, m.source_document_type, m.source_document_number, l.lot_number,
                ml.source_condition, ml.destination_condition, ($signed) AS `change`", [$warehouseId, $warehouseId])->get();
        $balance = $opening;
        $rows = [];
        foreach ($lines as $l) {
            if (D::isZero((string) $l->change)) {
                continue;   // e.g. a relocation inside the warehouse
            }
            $balance = D::add($balance, (string) $l->change);
            $rows[] = (array) $l + ['balance' => $balance];
        }

        return response()->json(['data' => ['opening_balance' => D::exact($opening), 'closing_balance' => $balance, 'lines' => $rows]]);
    }

    /** Resolve a scanned code: item barcode/SKU, variant SKU, packaging, lot, serial or storage location. */
    public function scan(Request $request, string $code): JsonResponse
    {
        $code = trim($code);
        $hit = fn (string $type, array $data) => response()->json(['data' => ['type' => $type] + $data]);
        if ($b = DB::table('item_barcodes as b')->join('items as i', 'i.id', '=', 'b.item_id')->leftJoin('item_packagings as p', 'p.id', '=', 'b.item_packaging_id')
            ->where('b.barcode', $code)->first(['b.item_id', 'i.sku', 'i.name', 'i.tracking_mode', 'b.item_variant_id', 'p.name as packaging', 'p.contained_qty'])) {
            return $hit('item', (array) $b);
        }
        if ($i = DB::table('items')->where('sku', $code)->first(['id as item_id', 'sku', 'name', 'tracking_mode'])) {
            return $hit('item', (array) $i);
        }
        if ($v = DB::table('item_variants as v')->join('items as i', 'i.id', '=', 'v.item_id')->where('v.sku', $code)
            ->first(['v.item_id', 'v.id as item_variant_id', 'i.sku', 'i.name', 'v.name_suffix', 'i.tracking_mode'])) {
            return $hit('variant', (array) $v);
        }
        if ($sl = DB::table('storage_locations')->where('barcode', $code)->orWhere('full_path', $code)->first(['id as storage_location_id', 'warehouse_id', 'full_path', 'usage'])) {
            return $hit('location', (array) $sl);
        }
        if ($s = DB::table('item_serials as s')->join('items as i', 'i.id', '=', 's.item_id')->where('s.serial_number', $code)
            ->first(['s.id as item_serial_id', 's.item_id', 'i.sku', 'i.name', 's.inventory_lot_id', 's.lifecycle_status', 's.current_warehouse_id'])) {
            return $hit('serial', (array) $s);
        }
        $lots = DB::table('inventory_lots as l')->join('items as i', 'i.id', '=', 'l.item_id')->where('l.lot_number', $code)->limit(20)
            ->get(['l.id as inventory_lot_id', 'l.lot_number', 'l.item_id', 'i.sku', 'i.name', 'l.expiry_date', 'l.qc_status', 'l.lock_status']);
        if ($lots->isNotEmpty()) {
            return $hit('lot', ['matches' => $lots->map(fn ($l) => (array) $l)->all()]);
        }

        throw DomainException::of(ErrorCode::NOT_FOUND, "Nothing matches `$code`.", ['code' => $code]);
    }

    public function pickSuggestions(Request $request, PickingPlanner $planner): JsonResponse
    {
        $actor = $this->actor($request);
        $warehouseId = $this->uuid($request, 'warehouse_id');
        if (! $this->access->can($actor, 'inventory.stock.read', $warehouseId)) {
            throw DomainException::permissionDenied('inventory.stock.read', $warehouseId);
        }
        $item = Item::query()->findOrFail($this->uuid($request, 'item_id'));
        $qty = (string) $request->query('quantity', '1');
        is_numeric($qty) && D::isPositive($qty) || throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Quantity must be positive.', ['field' => 'quantity']);

        return response()->json(['data' => $planner->plan($item, $request->query('item_variant_id'), $warehouseId, $qty, $request->query('lot_id'))]);
    }

    /** Forward lot trace: every movement of the lot (company-wide traceability permission). */
    public function lotTrace(Request $request, string $lotId): JsonResponse
    {
        $lot = DB::table('inventory_lots as l')->join('items as i', 'i.id', '=', 'l.item_id')->where('l.id', $lotId)
            ->first(['l.*', 'i.sku', 'i.name as item_name']) ?? throw DomainException::notFound('Lot');
        $moves = DB::table('stock_movement_lines as ml')->join('stock_movements as m', 'm.id', '=', 'ml.stock_movement_id')
            ->join('inventory_locations as sil', 'sil.id', '=', 'ml.source_inventory_location_id')->join('inventory_locations as dil', 'dil.id', '=', 'ml.destination_inventory_location_id')
            ->leftJoin('warehouses as sw', 'sw.id', '=', 'ml.source_warehouse_id')->leftJoin('warehouses as dw', 'dw.id', '=', 'ml.destination_warehouse_id')
            ->leftJoin('partners as p', 'p.id', '=', 'm.partner_id')->where('ml.inventory_lot_id', $lotId)->orderBy('m.posted_at')->limit(5000)
            ->get(['m.posted_at', 'm.movement_number', 'm.movement_type', 'm.source_document_type', 'm.source_document_number', 'sil.location_type as from_type',
                'sw.code as from_warehouse', 'dil.location_type as to_type', 'dw.code as to_warehouse', 'p.name as partner', 'ml.quantity']);
        $positions = DB::table('inventory_balances as b')->join('inventory_locations as il', 'il.id', '=', 'b.inventory_location_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'b.warehouse_id')->where('b.inventory_lot_id', $lotId)->where('b.quantity_on_hand', '<>', 0)
            ->get(['w.code as warehouse', 'il.location_type', 'b.stock_condition', 'b.quantity_on_hand']);

        return response()->json(['data' => ['lot' => (array) $lot, 'movements' => $moves->map(fn ($m) => (array) $m)->all(),
            'positions' => $positions->map(fn ($p) => (array) $p)->all()]]);
    }

    public function serialHistory(Request $request, string $serialNumber): JsonResponse
    {
        $serials = DB::table('item_serials as s')->join('items as i', 'i.id', '=', 's.item_id')->where('s.serial_number', $serialNumber)->get(['s.*', 'i.sku', 'i.name as item_name']);
        abort_if($serials->isEmpty(), 404);
        $history = DB::table('stock_movement_lines as ml')->join('stock_movements as m', 'm.id', '=', 'ml.stock_movement_id')
            ->join('inventory_locations as sil', 'sil.id', '=', 'ml.source_inventory_location_id')->join('inventory_locations as dil', 'dil.id', '=', 'ml.destination_inventory_location_id')
            ->leftJoin('warehouses as sw', 'sw.id', '=', 'ml.source_warehouse_id')->leftJoin('warehouses as dw', 'dw.id', '=', 'ml.destination_warehouse_id')
            ->leftJoin('partners as p', 'p.id', '=', 'm.partner_id')->whereIn('ml.item_serial_id', $serials->pluck('id'))->orderBy('m.posted_at')
            ->get(['m.posted_at', 'm.movement_number', 'm.movement_type', 'm.source_document_type', 'm.source_document_number', 'sil.location_type as from_type',
                'sw.code as from_warehouse', 'dil.location_type as to_type', 'dw.code as to_warehouse', 'p.name as partner', 'ml.quantity']);

        return response()->json(['data' => ['serials' => $serials->map(fn ($s) => (array) $s)->all(), 'history' => $history->map(fn ($h) => (array) $h)->all()]]);
    }

    public function demand(Request $request, DemandService $demand): JsonResponse
    {
        $itemId = $this->uuid($request, 'item_id');
        $warehouseId = $request->filled('warehouse_id') ? $this->uuid($request, 'warehouse_id') : null;
        if ($warehouseId !== null && ! $this->access->can($this->actor($request), 'planning.demand.read', $warehouseId)) {
            throw DomainException::permissionDenied('planning.demand.read', $warehouseId);
        }

        return response()->json(['data' => [
            'history' => $demand->history($itemId, $request->query('item_variant_id'), $warehouseId, (string) $request->query('from', now()->subYear()->toDateString()),
                (string) $request->query('to', now()->toDateString()), (string) $request->query('granularity', 'month')),
            'forecast' => $demand->dailyDemand($itemId, $request->query('item_variant_id'), $warehouseId),
        ]]);
    }

    public function replenishment(Request $request, ReplenishmentService $replenishment): JsonResponse
    {
        return response()->json(['data' => $replenishment->suggestions($this->actor($request), [
            'warehouse_id' => $request->filled('warehouse_id') ? $this->uuid($request, 'warehouse_id') : null,
            'item_id' => $request->filled('item_id') ? $this->uuid($request, 'item_id') : null,
            'below_only' => ! $request->boolean('all'),
        ])]);
    }

    private function scope(Builder $q, $actor, string $permission, string $column): void
    {
        $allowed = $this->access->warehouseIdsFor($actor, $permission);
        if ($allowed !== '*') {
            $q->whereIn($column, $allowed === [] ? ['00000000-0000-0000-0000-000000000000'] : $allowed);
        }
    }

    private function uuid(Request $request, string $param): string
    {
        $v = (string) $request->query($param, $request->input($param, ''));
        Str::isUuid($v) || throw DomainException::of(ErrorCode::VALIDATION_FAILED, "`$param` must be a UUID.", ['field' => $param]);

        return $v;
    }
}
