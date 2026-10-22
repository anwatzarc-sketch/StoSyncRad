<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Services;

use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Inventory\Enums\ReconciliationFindingStatus;
use App\Domains\Inventory\Enums\ReconciliationRunStatus;
use App\Domains\Inventory\Enums\StockCondition;
use App\Domains\Inventory\Models\ReconciliationFinding;
use App\Domains\Inventory\Models\ReconciliationRun;
use App\Domains\Inventory\Posting\BalanceLocker;
use App\Domains\Inventory\Posting\PositionKey;
use App\Domains\Messaging\Services\Outbox;
use App\Domains\Organization\Enums\InventoryLocationType;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Math\Decimal as D;
use App\Support\Sentinel;
use Illuminate\Support\Facades\DB;

/**
 * Ledger ↔ projection reconciliation (Chapter 07 §7.5): recompute every owned position from the
 * append-only ledger, record differences, and repair a projection row under a lock with an audit
 * reason. The ledger itself is never touched.
 */
final class ReconciliationService
{
    public function __construct(
        private readonly BalanceLocker $locker,
        private readonly AuditRecorder $audit,
        private readonly Outbox $outbox,
    ) {}

    /** @param  array{warehouse_id?: string, item_ids?: array<int, string>}  $scope */
    public function run(?UserAccount $actor, array $scope = []): ReconciliationRun
    {
        $run = ReconciliationRun::query()->forceCreate(['status' => ReconciliationRunStatus::RUNNING, 'scope' => $scope ?: null,
            'started_by' => $actor?->id, 'started_at' => now()]);
        try {
            $rows = $this->differences($scope);
            DB::transaction(function () use ($run, $rows) {
                foreach ($rows['findings'] as $f) {
                    ReconciliationFinding::query()->forceCreate(['reconciliation_run_id' => $run->id] + $f + ['status' => ReconciliationFindingStatus::OPEN]);
                }
                $run->forceFill([
                    'status' => $rows['findings'] === [] ? ReconciliationRunStatus::COMPLETED_CLEAN : ReconciliationRunStatus::COMPLETED_WITH_FINDINGS,
                    'positions_checked' => $rows['checked'], 'findings_count' => count($rows['findings']), 'finished_at' => now(),
                ])->save();
            });
        } catch (\Throwable $e) {
            $run->forceFill(['status' => ReconciliationRunStatus::FAILED, 'error' => mb_substr($e->getMessage(), 0, 2000), 'finished_at' => now()])->save();
            throw $e;
        }
        $this->audit->record($actor, 'inventory.reconciliation.completed', 'reconciliation_runs', $run->id, null,
            ['status' => $run->status->value, 'findings' => $run->findings_count, 'positions' => $run->positions_checked]);
        if ($run->findings_count > 0) {
            $this->outbox->publish('inventory.reconciliation.findings', 'reconciliation_runs', $run->id, ['findings' => $run->findings_count]);
        }

        return $run->refresh();
    }

    /** Set the projection of a finding's position to the ledger quantity (recomputed now, under lock). */
    public function repair(string $findingId, UserAccount $actor, string $reason): ReconciliationFinding
    {
        if (trim($reason) === '') {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'A repair needs a reason.', ['field' => 'reason']);
        }

        return DB::transaction(function () use ($findingId, $actor, $reason) {
            $finding = ReconciliationFinding::query()->lockForUpdate()->findOrFail($findingId);
            if (! in_array($finding->status, [ReconciliationFindingStatus::OPEN, ReconciliationFindingStatus::INVESTIGATING], true)) {
                throw DomainException::of(ErrorCode::INVALID_STATE_TRANSITION, 'This finding is already closed.');
            }
            $key = new PositionKey($finding->item_id, Sentinel::toNull($finding->item_variant_id), $finding->inventory_location_id,
                Sentinel::toNull($finding->inventory_lot_id), StockCondition::from($finding->stock_condition));
            $warehouseId = DB::table('inventory_locations')->where('id', $finding->inventory_location_id)->value('warehouse_id');
            $balance = $this->locker->lock([$key], [$key->locationId => $warehouseId])[$key->sortKey()];
            $ledger = $this->ledgerQuantity($key);
            $before = (string) $balance->quantity_on_hand;
            DB::update('UPDATE inventory_balances SET quantity_on_hand = ?, version = version + 1, updated_at = ? WHERE id = ?', [$ledger, now(), $balance->id]);
            $finding->forceFill(['status' => ReconciliationFindingStatus::REPAIRED, 'resolved_by' => $actor->id, 'resolved_at' => now(), 'resolution_note' => $reason])->save();
            $this->audit->record($actor, 'inventory.projection.repaired', 'inventory_balances', $balance->id, ['quantity_on_hand' => $before], ['quantity_on_hand' => $ledger], $reason);

            return $finding;
        });
    }

    /**
     * @param  array{warehouse_id?: string, item_ids?: array<int, string>}  $scope
     * @return array{checked: int, findings: array<int, array<string, mixed>>}
     */
    private function differences(array $scope): array
    {
        $owned = array_map(fn (InventoryLocationType $t) => $t->value, array_filter(InventoryLocationType::cases(), fn ($t) => $t->holdsOwnedStock()));
        $s = Sentinel::UUID;
        $filters = '';
        $bindings = [$s, $s, $s, $s];
        if (! empty($scope['item_ids'])) {
            $filters .= ' AND ml.item_id IN ('.implode(',', array_fill(0, count($scope['item_ids']), '?')).')';
            array_push($bindings, ...$scope['item_ids']);
        }
        $ledgerSql = "SELECT x.item_id, x.variant_id, x.location_id, x.lot_id, x.cond, SUM(x.q) AS q FROM (
                SELECT ml.item_id, COALESCE(ml.item_variant_id, ?) AS variant_id, ml.destination_inventory_location_id AS location_id,
                       COALESCE(ml.inventory_lot_id, ?) AS lot_id, ml.destination_condition AS cond, ml.quantity AS q
                  FROM stock_movement_lines ml WHERE true $filters
                UNION ALL
                SELECT ml.item_id, COALESCE(ml.item_variant_id, ?), ml.source_inventory_location_id,
                       COALESCE(ml.inventory_lot_id, ?), ml.source_condition, -ml.quantity
                  FROM stock_movement_lines ml WHERE true $filters
            ) x JOIN inventory_locations il ON il.id = x.location_id AND il.location_type IN ('".implode("','", $owned)."')
            GROUP BY x.item_id, x.variant_id, x.location_id, x.lot_id, x.cond";
        $bindings = array_merge([$s, $s], $scope['item_ids'] ?? [], [$s, $s], $scope['item_ids'] ?? []);
        $balanceFilter = '';
        $balanceBindings = [];
        if (! empty($scope['warehouse_id'])) {
            $balanceFilter .= ' AND (j.balance_warehouse_id = ? OR j.inventory_location_id IN (SELECT id FROM inventory_locations WHERE warehouse_id = ?))';
            array_push($balanceBindings, $scope['warehouse_id'], $scope['warehouse_id']);
        }
        if (! empty($scope['item_ids'])) {
            $balanceFilter .= ' AND j.item_id IN ('.implode(',', array_fill(0, count($scope['item_ids']), '?')).')';
            array_push($balanceBindings, ...$scope['item_ids']);
        }
        // MySQL has no FULL OUTER JOIN: every projection row with its ledger total, plus ledger positions
        // that have no projection row at all.
        $match = 'b.item_id = l.item_id AND b.item_variant_id = l.variant_id AND b.inventory_location_id = l.location_id
                  AND b.inventory_lot_id = l.lot_id AND b.stock_condition = l.cond';
        $sql = "WITH l AS ($ledgerSql),
                     b AS (SELECT * FROM inventory_balances WHERE owner_partner_id = '$s' AND package_id = '$s'),
                     j AS (SELECT b.item_id, b.item_variant_id, b.inventory_location_id, b.inventory_lot_id, b.stock_condition,
                                  b.warehouse_id AS balance_warehouse_id, COALESCE(l.q, 0) AS ledger_quantity, b.quantity_on_hand AS projection_quantity
                             FROM b LEFT JOIN l ON $match
                           UNION ALL
                           SELECT l.item_id, l.variant_id, l.location_id, l.lot_id, l.cond, NULL, l.q, 0
                             FROM l LEFT JOIN b ON $match WHERE b.id IS NULL)
            SELECT j.item_id, j.item_variant_id, j.inventory_location_id, j.inventory_lot_id, j.stock_condition, j.ledger_quantity, j.projection_quantity
              FROM j WHERE true $balanceFilter";
        $rows = DB::select($sql, array_merge($bindings, $balanceBindings));
        $findings = [];
        foreach ($rows as $r) {
            $diff = D::sub((string) $r->projection_quantity, (string) $r->ledger_quantity);
            if (! D::isZero($diff)) {
                $findings[] = ['item_id' => $r->item_id, 'item_variant_id' => $r->item_variant_id, 'inventory_location_id' => $r->inventory_location_id,
                    'inventory_lot_id' => $r->inventory_lot_id, 'stock_condition' => $r->stock_condition, 'ledger_quantity' => D::exact((string) $r->ledger_quantity),
                    'projection_quantity' => D::exact((string) $r->projection_quantity), 'difference' => $diff];
            }
        }

        return ['checked' => count($rows), 'findings' => $findings];
    }

    private function ledgerQuantity(PositionKey $key): string
    {
        $match = fn (string $side) => DB::table('stock_movement_lines')->where('item_id', $key->itemId)
            ->where("{$side}_inventory_location_id", $key->locationId)->where("{$side}_condition", $key->condition->value)
            ->where(fn ($q) => Sentinel::is($key->variantId) ? $q->whereNull('item_variant_id') : $q->where('item_variant_id', $key->variantId))
            ->where(fn ($q) => Sentinel::is($key->lotId) ? $q->whereNull('inventory_lot_id') : $q->where('inventory_lot_id', $key->lotId))
            ->sum('quantity');

        return D::sub((string) $match('destination'), (string) $match('source'));
    }
}
