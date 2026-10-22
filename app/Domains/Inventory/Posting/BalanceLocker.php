<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Posting;

use App\Domains\Inventory\Enums\StockCondition;
use App\Domains\Inventory\Models\InventoryBalance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Creates-if-missing and row-locks stock positions in the single global order (Chapter 09 §9.6):
 *   1. deduplicate keys   2. sort by PositionKey::sortKey()
 *   3. for each key in that order: lock it with a unique-index point lookup (SELECT … FOR UPDATE);
 *      if it does not exist yet, create it (INSERT … ON DUPLICATE KEY UPDATE, race-safe) and lock it.
 * InnoDB locks rows in the order it reads them, not in ORDER BY order, so positions are locked one at
 * a time rather than through a multi-row IN; and an existing row is never first share-locked by an
 * insert attempt, which would deadlock two postings upgrading to exclusive locks.
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
        ksort($unique, SORT_STRING);

        $now = now();
        $locked = [];
        foreach ($unique as $sortKey => $key) {
            $row = $this->lockOne($key);
            if ($row === null) {
                $columns = $key->columns();
                DB::insert(
                    'INSERT INTO inventory_balances (id, '.implode(', ', array_keys($columns)).', warehouse_id, quantity_on_hand, quantity_reserved, created_at, updated_at)
                     VALUES (?'.str_repeat(', ?', count($columns)).', ?, 0, 0, ?, ?) ON DUPLICATE KEY UPDATE id = id',
                    [(string) Str::uuid7(), ...array_values($columns), $warehouseByLocation[$key->locationId] ?? null, $now, $now]
                );
                $row = $this->lockOne($key) ?? throw new LogicException("Stock position $sortKey is missing after it was created.");
            }
            $locked[$sortKey] = (new InventoryBalance)->newFromBuilder((array) $row);
        }

        return $locked;
    }

    /**
     * Lock existing balance rows by id (reservation release/adjust paths) in the same global order as
     * lock(): identities are read without locking (they never change), sorted, then locked one by one.
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
        $order = [];
        foreach (DB::table('inventory_balances')->whereIn('id', $ids)->get() as $row) {
            $key = new PositionKey($row->item_id, $row->item_variant_id, $row->inventory_location_id, $row->inventory_lot_id,
                StockCondition::from($row->stock_condition), $row->owner_partner_id, $row->package_id);
            $order[$key->sortKey()] = $row->id;
        }
        ksort($order, SORT_STRING);

        $locked = [];
        foreach ($order as $id) {
            $row = DB::selectOne('SELECT * FROM inventory_balances WHERE id = ? FOR UPDATE', [$id]);
            if ($row !== null) {
                $locked[$id] = (new InventoryBalance)->newFromBuilder((array) $row);
            }
        }

        return $locked;
    }

    private function lockOne(PositionKey $key): ?object
    {
        $columns = $key->columns();
        $where = implode(' AND ', array_map(static fn (string $column) => "$column = ?", array_keys($columns)));

        return DB::selectOne(
            "SELECT * FROM inventory_balances FORCE INDEX (inventory_balances_position_uq) WHERE $where FOR UPDATE",
            array_values($columns)
        );
    }
}
