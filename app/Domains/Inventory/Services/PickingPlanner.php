<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Services;

use App\Domains\Catalog\Enums\RemovalStrategy;
use App\Domains\Catalog\Models\Item;
use App\Domains\Catalog\Services\CategoryPolicyResolver;
use App\Domains\Inventory\Enums\LotLockStatus;
use App\Domains\Inventory\Enums\LotQcStatus;
use App\Domains\Inventory\Enums\StockCondition;
use App\Domains\Organization\Enums\InventoryLocationType;
use App\Domains\Organization\Enums\StorageLocationStatus;
use App\Support\Math\Decimal as D;
use App\Support\Sentinel;
use Illuminate\Support\Facades\DB;

/**
 * Suggests eligible source positions for picking/reservation by removal strategy
 * (FEFO / FIFO / LIFO / closest location — Chapter 07 §7.7, Chapter 12 §12.3).
 * Suggestions are read without locks; callers lock and revalidate before using them.
 */
final class PickingPlanner
{
    public function __construct(private readonly CategoryPolicyResolver $policies) {}

    /**
     * @return array<int, array{balance_id: string, inventory_location_id: string, storage_location_id: string, location_path: string,
     *                          lot_id: ?string, lot_number: ?string, expiry_date: ?string, available: string, take: string}>
     */
    public function plan(Item $item, ?string $variantId, string $warehouseId, string $quantity, ?string $lotId = null, ?string $storageLocationId = null): array
    {
        $strategy = $this->policies->removalStrategy($item);
        $today = now()->toDateString();

        $q = DB::table('inventory_balances as b')
            ->join('inventory_locations as il', 'il.id', '=', 'b.inventory_location_id')
            ->join('storage_locations as sl', 'sl.id', '=', 'il.storage_location_id')
            ->leftJoin('inventory_lots as l', 'l.id', '=', 'b.inventory_lot_id')
            ->where('b.item_id', $item->id)
            ->where('b.item_variant_id', Sentinel::orId($variantId))
            ->where('b.warehouse_id', $warehouseId)
            ->where('b.stock_condition', StockCondition::AVAILABLE->value)
            ->where('b.is_frozen', false)
            ->where('il.location_type', InventoryLocationType::PHYSICAL->value)
            ->where('sl.is_pickable', true)
            ->where('sl.status', StorageLocationStatus::ACTIVE->value)
            ->whereRaw('b.quantity_on_hand - b.quantity_reserved > 0')
            ->where(function ($w) use ($today) {
                $w->whereNull('l.id')->orWhere(function ($lw) use ($today) {
                    $lw->whereIn('l.qc_status', [LotQcStatus::RELEASED->value, LotQcStatus::CONDITIONAL->value])
                        ->where('l.lock_status', LotLockStatus::UNLOCKED->value)
                        ->where(fn ($e) => $e->whereNull('l.expiry_date')->orWhere('l.expiry_date', '>', $today));
                });
            })
            ->select(['b.id as balance_id', 'b.inventory_location_id', 'sl.id as storage_location_id', 'sl.full_path as location_path',
                'l.id as lot_id', 'l.lot_number', 'l.expiry_date', 'b.created_at as balance_created_at', 'l.created_at as lot_created_at', 'sl.sort_order',
                DB::raw('(b.quantity_on_hand - b.quantity_reserved) as available')]);

        if ($lotId !== null) {
            $q->where('b.inventory_lot_id', $lotId);
        }
        if ($storageLocationId !== null) {
            $q->where('sl.id', $storageLocationId);
        }

        match ($strategy) {
            RemovalStrategy::FEFO => $q->orderByRaw('l.expiry_date IS NULL, l.expiry_date ASC')->orderByRaw('COALESCE(l.created_at, b.created_at) ASC'),
            RemovalStrategy::LIFO => $q->orderByRaw('COALESCE(l.created_at, b.created_at) DESC'),
            RemovalStrategy::CLOSEST_LOCATION => $q->orderBy('sl.sort_order')->orderBy('sl.full_path'),
            default => $q->orderByRaw('COALESCE(l.created_at, b.created_at) ASC'),
        };
        $q->orderBy('b.id');

        $remaining = $quantity;
        $plan = [];
        foreach ($q->limit(500)->get() as $row) {
            if (! D::isPositive($remaining)) {
                break;
            }
            $take = D::min(D::exact($row->available), $remaining);
            $plan[] = [
                'balance_id' => $row->balance_id,
                'inventory_location_id' => $row->inventory_location_id,
                'storage_location_id' => $row->storage_location_id,
                'location_path' => $row->location_path,
                'lot_id' => $row->lot_id,
                'lot_number' => $row->lot_number,
                'expiry_date' => $row->expiry_date,
                'available' => D::exact($row->available),
                'take' => $take,
            ];
            $remaining = D::sub($remaining, $take);
        }

        return $plan;
    }
}
