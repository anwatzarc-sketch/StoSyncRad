<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Posting;

use App\Domains\Inventory\Models\InventoryBalance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates-if-missing and row-locks stock positions in the single global order (Chapter 09 §9.6):
 *   1. deduplicate keys   2. sort by PositionKey::sortKey()
 *   3. INSERT … ON CONFLICT DO NOTHING (the unique constraint makes creation race-safe)
 *   4. SELECT … FOR UPDATE ORDER BY the same columns (byte order, matching PHP strcmp)
 * Locks are held until the surrounding transaction ends.
 */
final class BalanceLocker
{
    /**
     * @param  array<int, PositionKey>  $keys
     * @param  array<string, ?string>  $warehouseByLocation  inventory location id → warehouse id
     * @return array<string, InventoryBalance> sortKey → locked balance
     */
    public function lock(array $keys, array $warehouseByLocation): array
    {
        $unique = [];
        foreach ($keys as $key) {
            $unique[$key->sortKey()] = $key;
        }
        if ($unique === []) {
            return [];
        }
        ksort($unique, SORT_STRING);

        $now = now();
        $rows = [];
        foreach ($unique as $key) {
            $rows[] = $key->columns() + [
                'id' => (string) Str::uuid7(),
                'warehouse_id' => $warehouseByLocation[$key->locationId] ?? null,
                'quantity_on_hand' => '0',
                'quantity_reserved' => '0',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('inventory_balances')->insertOrIgnore($rows);

        $tuples = [];
        $bindings = [];
        foreach ($unique as $key) {
            $tuples[] = '(?::uuid, ?::uuid, ?::uuid, ?::uuid, ?, ?::uuid, ?::uuid)';
            array_push($bindings, ...array_values($key->columns()));
        }
        $sql = 'SELECT * FROM inventory_balances
                 WHERE (item_id, item_variant_id, inventory_location_id, inventory_lot_id, stock_condition, owner_partner_id, package_id)
                       IN ('.implode(', ', $tuples).')
              ORDER BY item_id, item_variant_id, inventory_location_id, inventory_lot_id, stock_condition COLLATE "C", owner_partner_id, package_id
                 FOR UPDATE';

        $locked = [];
        foreach (DB::select($sql, $bindings) as $row) {
            $balance = (new InventoryBalance)->newFromBuilder((array) $row);
            $key = new PositionKey($balance->item_id, $balance->item_variant_id, $balance->inventory_location_id,
                $balance->inventory_lot_id, $balance->stock_condition, $balance->owner_partner_id, $balance->package_id);
            $locked[$key->sortKey()] = $balance;
        }

        return $locked;
    }

    /**
     * Lock existing balance rows by id (reservation release/adjust paths), in id order.
     *
     * @param  array<int, string>  $ids
     * @return array<string, InventoryBalance> id → balance
     */
    public function lockByIds(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }
        sort($ids, SORT_STRING);
        $rows = InventoryBalance::query()->whereIn('id', $ids)->orderBy('item_id')->orderBy('item_variant_id')
            ->orderBy('inventory_location_id')->orderBy('inventory_lot_id')->orderByRaw('stock_condition COLLATE "C"')
            ->orderBy('owner_partner_id')->orderBy('package_id')->lockForUpdate()->get();

        return $rows->keyBy('id')->all();
    }
}
