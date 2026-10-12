<?php

declare(strict_types=1);

namespace App\Domains\Stocktaking\Services;

use App\Domains\IdentityAccess\Enums\DocumentAction;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\IdentityAccess\Services\ApprovalService;
use App\Domains\Inventory\Enums\StockCondition;
use App\Domains\Inventory\Posting\BalanceLocker;
use App\Domains\Inventory\Services\InventoryLocationResolver;
use App\Domains\Inventory\Services\ValuationService;
use App\Domains\Organization\Enums\DocumentType;
use App\Domains\Organization\Enums\InventoryLocationType;
use App\Domains\Organization\Enums\WarehouseCapability;
use App\Domains\Organization\Models\StorageLocation;
use App\Domains\Organization\Models\Warehouse;
use App\Domains\Platform\Config\PlatformConfig;
use App\Domains\Stocktaking\Enums\AdjustmentType;
use App\Domains\Stocktaking\Enums\CountLineStatus as L;
use App\Domains\Stocktaking\Enums\StockAdjustmentStatus;
use App\Domains\Stocktaking\Enums\StocktakeFreezePolicy;
use App\Domains\Stocktaking\Enums\StocktakeStatus as S;
use App\Domains\Stocktaking\Enums\StocktakeType;
use App\Domains\Stocktaking\Models\StockAdjustment;
use App\Domains\Stocktaking\Models\Stocktake;
use App\Domains\Stocktaking\Models\StocktakeLine;
use App\Support\Documents\LineQuantities;
use App\Support\Documents\ManagesDocuments;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Math\Decimal as D;
use App\Support\Sentinel;
use App\Support\Transactions\PostingContext;
use App\Support\Transactions\PostingOutcome;
use App\Support\Transactions\PostingPipeline;
use Illuminate\Support\Facades\DB;

/**
 * Stocktaking (Chapter 08 §8.10): snapshot (and optionally freeze) → blind counts → recount above
 * threshold → review → approval above thresholds → one posting that books the variance through a
 * stock adjustment and releases the freeze. Snapshot mode reconciles movements posted between the
 * snapshot and each count, so counting never blocks operations.
 */
final class StocktakeWorkflow
{
    use ManagesDocuments;

    public function __construct(
        private readonly PlatformConfig $config,
        private readonly ApprovalService $approvals,
        private readonly PostingPipeline $pipeline,
        private readonly BalanceLocker $locker,
        private readonly InventoryLocationResolver $locations,
        private readonly ValuationService $valuation,
        private readonly LineQuantities $lines,
        private readonly StockAdjustmentWorkflow $adjustments,
    ) {}

    /** @param  array<string, mixed>  $data  validated against StocktakeSchema */
    public function save(array $data, UserAccount $actor, ?string $id = null): Stocktake
    {
        return DB::transaction(function () use ($data, $actor, $id) {
            if ($id === null) {
                $warehouse = Warehouse::query()->findOrFail($data['warehouse_id']);
                $this->authorizeWarehouse($actor, 'stocktaking.stocktake.create', $warehouse->id);
                $warehouse->assertCapability(WarehouseCapability::STOCKTAKE);
                $scope = ['warehouse' => $warehouse->id];
                $st = new Stocktake;
                $st->forceFill([
                    'document_number' => $this->nextNumber(DocumentType::STOCKTAKE, $warehouse->id), 'warehouse_id' => $warehouse->id, 'branch_id' => $warehouse->branch_id,
                    'status' => S::DRAFT, 'created_by' => $actor->id, 'document_date' => $data['document_date'] ?? now()->toDateString(),
                    'stocktake_type' => StocktakeType::from($data['stocktake_type'] ?? (string) $this->config->get('stocktaking.default_type', $scope)),
                    'freeze_policy' => StocktakeFreezePolicy::from($data['freeze_policy'] ?? (string) $this->config->get('stocktaking.default_freeze_policy', $scope)),
                    'is_blind' => $data['is_blind'] ?? $this->config->bool('stocktaking.blind_by_default', $scope),
                    'scope' => $this->normalizeScope($data['scope'] ?? []),
                ]);
            } else {
                $st = $this->lockDocument(Stocktake::class, $id);
                $this->authorizeWarehouse($actor, 'stocktaking.stocktake.update', $st->warehouse_id);
                $this->expectVersion($st, $data['version'] ?? null);
                $this->assertStatus($st, [S::DRAFT, S::SCHEDULED]);
                foreach (['stocktake_type' => StocktakeType::class, 'freeze_policy' => StocktakeFreezePolicy::class] as $field => $enum) {
                    if (isset($data[$field])) {
                        $st->forceFill([$field => $enum::from($data[$field])]);
                    }
                }
                if (array_key_exists('is_blind', $data)) {
                    $st->forceFill(['is_blind' => (bool) $data['is_blind']]);
                }
                if (array_key_exists('scope', $data)) {
                    $st->forceFill(['scope' => $this->normalizeScope($data['scope'] ?? [])]);
                }
                $st->forceFill(['version' => $st->version + 1]);
            }
            $st->fill(array_intersect_key($data, array_flip(['scheduled_for', 'document_date', 'priority', 'instructions', 'reference', 'notes'])));
            $st->forceFill(['updated_by' => $actor->id])->save();
            $this->audit($actor, $id === null ? 'stocktaking.stocktake.created' : 'stocktaking.stocktake.updated', $st, null, ['scope' => $st->scope]);

            return $st->refresh();
        });
    }

    public function schedule(string $id, UserAccount $actor, ?int $version = null): Stocktake
    {
        return DB::transaction(function () use ($id, $actor, $version) {
            $st = $this->lockDocument(Stocktake::class, $id);
            $this->authorizeWarehouse($actor, 'stocktaking.stocktake.schedule', $st->warehouse_id);
            $this->expectVersion($st, $version);
            $this->transition($st, S::SCHEDULED);
            $this->audit($actor, 'stocktaking.stocktake.scheduled', $st);
            $this->publish('stocktaking.stocktake.scheduled', $st, ['document_number' => $st->document_number, 'scheduled_for' => $st->scheduled_for]);

            return $st;
        });
    }

    /** Take the snapshot (and freeze positions per policy); counting starts. */
    public function start(string $id, UserAccount $actor, ?int $version = null): Stocktake
    {
        return DB::transaction(function () use ($id, $actor, $version) {
            $st = $this->lockDocument(Stocktake::class, $id);
            $this->authorizeWarehouse($actor, 'stocktaking.stocktake.schedule', $st->warehouse_id);
            $this->expectVersion($st, $version);
            Warehouse::query()->findOrFail($st->warehouse_id)->assertOperational();
            $balances = $this->balancesInScope($st)->orderBy('b.id')->pluck('b.id')->all();
            $locked = $balances === [] ? [] : $this->locker->lockByIds($balances); // global order; blocks concurrent postings briefly
            $frozenElsewhere = collect($locked)->first(fn ($b) => $b->is_frozen);
            if ($frozenElsewhere !== null) {
                throw DomainException::of(ErrorCode::POSITION_FROZEN, 'Part of this scope is frozen by another open stocktake.', ['stocktake_id' => $frozenElsewhere->frozen_by_stocktake_id]);
            }
            $snapshotAt = now();
            $no = 0;
            foreach ($this->balancesInScope($st)->orderBy('sl.sort_order')->orderBy('sl.full_path')->orderBy('b.id')
                ->get(['b.id', 'b.item_id', 'b.item_variant_id', 'b.inventory_lot_id', 'b.stock_condition', 'b.quantity_on_hand', 'il.storage_location_id']) as $b) {
                StocktakeLine::query()->forceCreate([
                    'stocktake_id' => $st->id, 'line_no' => ++$no, 'inventory_balance_id' => $b->id, 'item_id' => $b->item_id,
                    'item_variant_id' => Sentinel::toNull($b->item_variant_id), 'storage_location_id' => $b->storage_location_id,
                    'inventory_lot_id' => Sentinel::toNull($b->inventory_lot_id), 'stock_condition' => $b->stock_condition,
                    'snapshot_quantity' => $b->quantity_on_hand, 'count_status' => L::PENDING,
                    'unit_cost_snapshot' => $this->valuation->estimateCost($b->item_id, Sentinel::toNull($b->item_variant_id), $this->valuation->bucket($this->locations->forStorageLocation($b->storage_location_id))),
                ]);
            }
            if ($st->freeze_policy === StocktakeFreezePolicy::FREEZE_POSITIONS && $balances !== []) {
                DB::table('inventory_balances')->whereIn('id', $balances)->update(['is_frozen' => true, 'frozen_by_stocktake_id' => $st->id, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
            }
            $this->transition($st, S::COUNTING, ['snapshot_at' => $snapshotAt, 'submitted_by' => $actor->id, 'submitted_at' => $snapshotAt]);
            $this->audit($actor, 'stocktaking.stocktake.started', $st, null, ['lines' => $no, 'frozen' => $st->freeze_policy === StocktakeFreezePolicy::FREEZE_POSITIONS]);

            return $st;
        });
    }

    /**
     * Enter counts (status counting) or recounts (status recount_required), plus found stock.
     *
     * @param  array<string, mixed>  $data  validated against StocktakeCountSchema
     */
    public function count(string $id, UserAccount $actor, array $data): Stocktake
    {
        return DB::transaction(function () use ($id, $actor, $data) {
            $st = $this->lockDocument(Stocktake::class, $id);
            $recount = $st->status === S::RECOUNT_REQUIRED;
            $this->authorizeWarehouse($actor, $recount ? 'stocktaking.stocktake.recount' : 'stocktaking.stocktake.count', $st->warehouse_id);
            $this->assertStatus($st, [S::COUNTING, S::RECOUNT_REQUIRED]);
            $lines = StocktakeLine::query()->where('stocktake_id', $st->id)->lockForUpdate()->get()->keyBy('id');
            foreach ((array) ($data['counts'] ?? []) as $i => $c) {
                $line = $lines->get($c['line_id']) ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Unknown stocktake line.', ['field' => "counts.$i.line_id"]);
                if (! empty($c['excluded'])) {
                    $line->forceFill(['count_status' => L::EXCLUDED, 'notes' => $c['notes'] ?? $line->notes])->save();

                    continue;
                }
                if (! isset($c['quantity'])) {
                    throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Enter the counted quantity.', ['field' => "counts.$i.quantity"]);
                }
                if ($recount) {
                    if ($line->count_status !== L::RECOUNT_REQUIRED) {
                        throw DomainException::of(ErrorCode::VALIDATION_FAILED, "Line {$line->line_no} does not need a recount.", ['field' => "counts.$i.line_id"]);
                    }
                    $line->forceFill(['recount_quantity' => D::exact($c['quantity']), 'recounted_by' => $actor->id, 'recounted_at' => now(), 'count_status' => L::RECOUNTED]);
                } else {
                    $line->forceFill(['counted_quantity' => D::exact($c['quantity']), 'counted_by' => $actor->id, 'counted_at' => now(), 'count_status' => L::COUNTED]);
                }
                $line->forceFill(['notes' => $c['notes'] ?? $line->notes])->save();
            }
            $no = (int) $lines->max('line_no');
            foreach ((array) ($data['unexpected'] ?? []) as $i => $f) {
                if ($recount) {
                    throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Found stock is recorded during the first count.', ['field' => "unexpected.$i"]);
                }
                $storage = StorageLocation::query()->findOrFail($f['storage_location_id']);
                if ($storage->warehouse_id !== $st->warehouse_id) {
                    throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'The location belongs to another warehouse.', ['field' => "unexpected.$i.storage_location_id"]);
                }
                $item = $this->lines->item($f['item_id'], false);
                StocktakeLine::query()->forceCreate([
                    'stocktake_id' => $st->id, 'line_no' => ++$no, 'item_id' => $item->id, 'item_variant_id' => $f['item_variant_id'] ?? null,
                    'storage_location_id' => $storage->id, 'inventory_lot_id' => $f['inventory_lot_id'] ?? null, 'stock_condition' => StockCondition::AVAILABLE,
                    'snapshot_quantity' => '0', 'counted_quantity' => D::exact($f['quantity']), 'counted_by' => $actor->id, 'counted_at' => now(),
                    'count_status' => L::COUNTED, 'is_unexpected' => true, 'notes' => $f['notes'] ?? null,
                    'unit_cost_snapshot' => $this->valuation->estimateCost($item->id, $f['item_variant_id'] ?? null, $this->valuation->bucket($this->locations->forStorageLocation($storage->id))),
                ]);
            }
            $this->audit($actor, $recount ? 'stocktaking.stocktake.recounted' : 'stocktaking.stocktake.counted', $st, null,
                ['lines' => count($data['counts'] ?? []), 'unexpected' => count($data['unexpected'] ?? [])]);

            return $st;
        });
    }

    /**
     * Close counting: reconcile movements since the snapshot, compute variances, and either request
     * recounts (above the recount threshold) or move to review.
     */
    public function finishCounting(string $id, UserAccount $actor, ?int $version = null): Stocktake
    {
        return DB::transaction(function () use ($id, $actor, $version) {
            $st = $this->lockDocument(Stocktake::class, $id);
            $this->authorizeWarehouse($actor, 'stocktaking.stocktake.count', $st->warehouse_id);
            $this->expectVersion($st, $version);
            $this->assertStatus($st, [S::COUNTING, S::RECOUNT_REQUIRED]);
            $lines = StocktakeLine::query()->where('stocktake_id', $st->id)->orderBy('line_no')->lockForUpdate()->get();
            $pending = $lines->filter(fn ($l) => in_array($l->count_status, [L::PENDING, L::RECOUNT_REQUIRED], true));
            if ($pending->isNotEmpty()) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, $pending->count().' line(s) still need a count (or exclude them).',
                    ['lines' => $pending->pluck('line_no')->take(50)->values()->all()]);
            }
            $scope = ['warehouse' => $st->warehouse_id];
            $recountPct = (string) $this->config->get('stocktaking.recount_threshold_percent', $scope);
            $needRecount = 0;
            foreach ($lines as $line) {
                if ($line->count_status === L::EXCLUDED) {
                    continue;
                }
                $countedAt = $line->recounted_at ?? $line->counted_at;
                $moved = $st->freeze_policy === StocktakeFreezePolicy::FREEZE_POSITIONS ? '0' : $this->movementBetween($line, $st->snapshot_at, $countedAt);
                $expected = D::add($line->snapshot_quantity, $moved);
                $final = D::exact($line->recount_quantity ?? $line->counted_quantity);
                $variance = D::sub($final, $expected);
                $line->forceFill(['movement_since_snapshot' => $moved, 'final_quantity' => $final, 'variance_quantity' => $variance,
                    'variance_value' => D::mul($variance, $line->unit_cost_snapshot, D::MONEY_SCALE)]);
                if ($line->count_status === L::COUNTED && D::gt($this->variancePercent($variance, $expected), $recountPct)) {
                    $line->forceFill(['count_status' => L::RECOUNT_REQUIRED]);
                    $needRecount++;
                }
                $line->save();
            }
            $this->transition($st, $needRecount > 0 ? S::RECOUNT_REQUIRED : S::REVIEW);
            $this->audit($actor, 'stocktaking.stocktake.counting_finished', $st, null, ['recounts' => $needRecount]);

            return $st;
        });
    }

    /** Supervisor review: verify lines; above thresholds the stocktake needs approval. */
    public function review(string $id, UserAccount $actor, ?int $version = null): Stocktake
    {
        return DB::transaction(function () use ($id, $actor, $version) {
            $st = $this->lockDocument(Stocktake::class, $id);
            $this->authorizeWarehouse($actor, 'stocktaking.stocktake.review', $st->warehouse_id);
            $this->expectVersion($st, $version);
            $this->assertStatus($st, [S::REVIEW]);
            $scope = ['warehouse' => $st->warehouse_id];
            $supervisorPct = (string) $this->config->get('stocktaking.supervisor_threshold_percent', $scope);
            $approvalValue = (string) $this->config->get('stocktaking.approval_threshold_value', $scope);
            $absValue = '0';
            $needsApproval = false;
            foreach (StocktakeLine::query()->where('stocktake_id', $st->id)->where('count_status', '<>', L::EXCLUDED->value)->get() as $line) {
                $absValue = D::add($absValue, D::of($line->variance_value)->abs(), D::MONEY_SCALE);
                $expected = D::add($line->snapshot_quantity, $line->movement_since_snapshot);
                $needsApproval = $needsApproval || D::gt($this->variancePercent($line->variance_quantity, $expected), $supervisorPct);
                $line->forceFill(['count_status' => L::VERIFIED])->save();
            }
            $needsApproval = $needsApproval || D::gt($absValue, $approvalValue);
            $this->transition($st, $needsApproval ? S::PENDING_APPROVAL : S::APPROVED, ['reviewed_by' => $actor->id, 'reviewed_at' => now()]
                + ($needsApproval ? [] : ['approved_by' => $actor->id, 'approved_at' => now()]));
            $this->audit($actor, 'stocktaking.stocktake.reviewed', $st, null, ['variance_value_abs' => $absValue, 'needs_approval' => $needsApproval]);

            return $st;
        });
    }

    /** Send lines back for recount from review. @param array<int, string> $lineIds */
    public function requestRecount(string $id, UserAccount $actor, array $lineIds): Stocktake
    {
        return DB::transaction(function () use ($id, $actor, $lineIds) {
            $st = $this->lockDocument(Stocktake::class, $id);
            $this->authorizeWarehouse($actor, 'stocktaking.stocktake.review', $st->warehouse_id);
            StocktakeLine::query()->where('stocktake_id', $st->id)->whereIn('id', $lineIds)
                ->update(['count_status' => L::RECOUNT_REQUIRED->value, 'recount_quantity' => null, 'recounted_by' => null, 'recounted_at' => null, 'updated_at' => now()]);
            $this->transition($st, S::RECOUNT_REQUIRED);
            $this->audit($actor, 'stocktaking.stocktake.recount_requested', $st, null, ['lines' => count($lineIds)]);

            return $st;
        });
    }

    public function approve(string $id, UserAccount $actor, ?int $version = null, ?string $comment = null, ?string $overrideReason = null): Stocktake
    {
        return DB::transaction(function () use ($id, $actor, $version, $comment, $overrideReason) {
            $st = $this->lockDocument(Stocktake::class, $id);
            $this->expectVersion($st, $version);
            $this->assertStatus($st, [S::PENDING_APPROVAL]);
            $counters = StocktakeLine::query()->where('stocktake_id', $st->id)->get(['counted_by', 'recounted_by'])
                ->flatMap(fn ($l) => [$l->counted_by, $l->recounted_by])->filter()->unique()->values()->all();
            if ($this->config->bool('stocktaking.counter_cannot_approve') && in_array($actor->id, $counters, true)) {
                throw DomainException::sodViolation('stocktaking.counter_cannot_approve', 'You counted stock on this stocktake and cannot approve it.');
            }
            $value = D::trim((string) StocktakeLine::query()->where('stocktake_id', $st->id)->selectRaw('COALESCE(SUM(ABS(variance_value)), 0) AS v')->value('v'));
            $actors = $this->documentActors($st, DocumentType::STOCKTAKE) + [DocumentAction::COUNT->value => $counters];
            $this->enforceSod(DocumentType::STOCKTAKE, DocumentAction::APPROVE, $actors, $actor, $st->id, $value, null, $overrideReason);
            $complete = $this->approvals->approve(DocumentType::STOCKTAKE, $st->id, $actor, $value, null, ['warehouse_id' => $st->warehouse_id, 'branch_id' => $st->branch_id], $comment);
            if ($complete) {
                $this->transition($st, S::APPROVED, ['approved_by' => $actor->id, 'approved_at' => now()]);
            } else {
                $st->forceFill(['version' => $st->version + 1])->save();
            }
            $this->audit($actor, 'stocktaking.stocktake.approval_recorded', $st, null, ['fully_approved' => $complete, 'value' => $value], $comment);

            return $st;
        });
    }

    /** Post once: book the variances through a count-variance adjustment and release the freeze. */
    public function post(string $id, UserAccount $actor, ?string $idempotencyKey, array $options = []): PostingOutcome
    {
        return $this->pipeline->run($actor, 'stocktaking.stocktake.post', $idempotencyKey, ['stocktake_id' => $id] + $options,
            function (PostingContext $ctx) use ($id, $actor, $options) {
                $st = $this->lockDocument(Stocktake::class, $id);
                $this->authorizeWarehouse($actor, 'stocktaking.stocktake.post', $st->warehouse_id);
                $this->expectVersion($st, $options['version'] ?? null);
                if ($st->status === S::POSTED) {
                    throw DomainException::alreadyPosted('Stocktake', $st->document_number);
                }
                $this->assertStatus($st, [S::APPROVED]);
                $variances = StocktakeLine::query()->where('stocktake_id', $st->id)->where('count_status', L::VERIFIED->value)
                    ->where('variance_quantity', '<>', 0)->orderBy('line_no')->get();
                $movements = [];
                $adjustment = null;
                if ($variances->isNotEmpty()) {
                    $adjustment = new StockAdjustment;
                    $adjustment->forceFill([
                        'document_number' => $this->nextNumber(DocumentType::STOCK_ADJUSTMENT, $st->warehouse_id), 'warehouse_id' => $st->warehouse_id,
                        'branch_id' => $st->branch_id, 'adjustment_type' => AdjustmentType::COUNT_VARIANCE, 'status' => StockAdjustmentStatus::APPROVED,
                        'source_stocktake_id' => $st->id, 'reason' => "Stocktake {$st->document_number} count variance", 'document_date' => now()->toDateString(),
                        'created_by' => $actor->id, 'approved_by' => $st->approved_by, 'approved_at' => $st->approved_at,
                    ])->save();
                    $rows = [];
                    foreach ($variances as $line) {
                        if ($this->lines->item($line->item_id, false)->tracking_mode->requiresSerial()) {
                            throw DomainException::of(ErrorCode::POLICY_VIOLATION, "Line {$line->line_no}: serialized variances must be resolved with an adjustment that names the serials; exclude the line first.");
                        }
                        $rows[] = ['item_id' => $line->item_id, 'item_variant_id' => $line->item_variant_id, 'storage_location_id' => $line->storage_location_id,
                            'inventory_lot_id' => $line->inventory_lot_id, 'stock_condition' => $line->stock_condition->value, 'quantity' => $line->variance_quantity,
                            'unit_cost' => D::isPositive($line->variance_quantity) ? $line->unit_cost_snapshot : null, 'stocktake_line_id' => $line->id,
                            'reason' => $line->is_unexpected ? 'Found during count' : null];
                    }
                    $this->adjustments->replaceLines($adjustment, $rows);
                    $movements = $this->adjustments->postLines($adjustment, $ctx, $actor, [$st->id]);
                }
                $this->unfreeze($st);
                $st->forceFill(['status' => S::POSTED, 'posted_by' => $actor->id, 'posted_at' => now(), 'stock_adjustment_id' => $adjustment?->id, 'version' => $st->version + 1])->save();
                $ctx->audit('stocktaking.stocktake.posted', 'stocktakes', $st->id, null, ['adjustment' => $adjustment?->document_number, 'variance_lines' => $variances->count()]);
                $ctx->publish('stocktaking.stocktake.posted', 'stocktakes', $st->id, ['document_number' => $st->document_number, 'warehouse_id' => $st->warehouse_id]);

                return new PostingOutcome(['stocktake_id' => $st->id, 'document_number' => $st->document_number, 'status' => S::POSTED->value,
                    'stock_adjustment_id' => $adjustment?->id, 'movement_numbers' => array_map(fn ($m) => $m->movement_number, $movements)], 201, DocumentType::STOCKTAKE->value, $st->id);
            }, failureAudit: ['stocktaking.stocktake.post_failed', 'stocktakes', $id]);
    }

    public function cancel(string $id, UserAccount $actor, ?string $reason, ?int $version = null): Stocktake
    {
        return DB::transaction(function () use ($id, $actor, $reason, $version) {
            $st = $this->lockDocument(Stocktake::class, $id);
            $this->authorizeWarehouse($actor, 'stocktaking.stocktake.cancel', $st->warehouse_id);
            $this->expectVersion($st, $version);
            $this->transition($st, S::CANCELLED, ['cancelled_by' => $actor->id, 'cancelled_at' => now(), 'cancellation_reason' => $reason]);
            $this->unfreeze($st);
            $this->audit($actor, 'stocktaking.stocktake.cancelled', $st, null, null, $reason);

            return $st;
        });
    }

    private function unfreeze(Stocktake $st): void
    {
        $ids = DB::table('inventory_balances')->where('frozen_by_stocktake_id', $st->id)->orderBy('id')->pluck('id')->all();
        if ($ids !== []) {
            $this->locker->lockByIds($ids);
            DB::table('inventory_balances')->whereIn('id', $ids)->update(['is_frozen' => false, 'frozen_by_stocktake_id' => null, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
        }
    }

    /** Positions with stock in the stocktake's scope (physical locations of the warehouse). */
    private function balancesInScope(Stocktake $st): \Illuminate\Database\Query\Builder
    {
        $scope = (array) $st->scope;
        $q = DB::table('inventory_balances as b')->join('inventory_locations as il', 'il.id', '=', 'b.inventory_location_id')
            ->join('storage_locations as sl', 'sl.id', '=', 'il.storage_location_id')->join('items as i', 'i.id', '=', 'b.item_id')
            ->where('b.warehouse_id', $st->warehouse_id)->where('il.location_type', InventoryLocationType::PHYSICAL->value)
            ->where('b.quantity_on_hand', '<>', 0);
        if (! empty($scope['storage_location_ids'])) {
            $paths = StorageLocation::query()->whereIn('id', $scope['storage_location_ids'])->pluck('full_path');
            $q->where(function ($w) use ($paths) {   // a location includes its children
                foreach ($paths as $p) {
                    $w->orWhere('sl.full_path', $p)->orWhere('sl.full_path', 'like', str_replace(['%', '_'], ['\\%', '\\_'], $p).'/%');
                }
            });
        }
        if (! empty($scope['category_ids'])) {
            $q->whereIn('i.category_id', DB::table('item_categories as c')->join('item_categories as root', function ($j) {
                $j->whereRaw("c.code_path = root.code_path OR c.code_path LIKE root.code_path || '/%'");
            })->whereIn('root.id', $scope['category_ids'])->select('c.id'));
        }
        if (! empty($scope['item_ids'])) {
            $q->whereIn('b.item_id', $scope['item_ids']);
        }
        if (! empty($scope['abc_classes'])) {
            $q->whereIn('i.abc_class', $scope['abc_classes']);
        }

        return $q;
    }

    /** Net quantity posted into (+) / out of (−) the counted position between two instants. */
    private function movementBetween(StocktakeLine $line, mixed $from, mixed $to): string
    {
        if ($from === null || $to === null) {
            return '0';
        }
        $location = $this->locations->forStorageLocation($line->storage_location_id)->id;
        $lotMatch = fn ($q) => $line->inventory_lot_id === null ? $q->whereNull('ml.inventory_lot_id') : $q->where('ml.inventory_lot_id', $line->inventory_lot_id);
        $variantMatch = fn ($q) => $line->item_variant_id === null ? $q->whereNull('ml.item_variant_id') : $q->where('ml.item_variant_id', $line->item_variant_id);
        $base = fn () => DB::table('stock_movement_lines as ml')->join('stock_movements as m', 'm.id', '=', 'ml.stock_movement_id')
            ->where('ml.item_id', $line->item_id)->where('m.posted_at', '>', $from)->where('m.posted_at', '<=', $to)
            ->where($lotMatch)->where($variantMatch);
        $in = (string) $base()->where('ml.destination_inventory_location_id', $location)->where('ml.destination_condition', $line->stock_condition->value)->sum('ml.quantity');
        $out = (string) $base()->where('ml.source_inventory_location_id', $location)->where('ml.source_condition', $line->stock_condition->value)->sum('ml.quantity');

        return D::sub($in, $out);
    }

    private function variancePercent(string $variance, string $expected): string
    {
        $abs = D::of($variance)->abs();
        if ($abs->isZero()) {
            return '0';
        }
        if (! D::isPositive($expected)) {
            return '100';
        }

        return D::div(D::mul($abs, '100', D::QTY_SCALE), $expected, 4);
    }

    /** @param  array<string, mixed>  $scope */
    private function normalizeScope(array $scope): array
    {
        $allowed = ['storage_location_ids', 'category_ids', 'item_ids', 'abc_classes'];
        $unknown = array_diff(array_keys($scope), $allowed);
        if ($unknown !== []) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Unknown scope keys: '.implode(', ', $unknown).'. Allowed: '.implode(', ', $allowed).'.', ['field' => 'scope']);
        }
        foreach ($scope as $k => $v) {
            if (! is_array($v) || array_filter($v, fn ($x) => ! is_string($x)) !== []) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, "Scope `$k` must be a list of strings.", ['field' => "scope.$k"]);
            }
        }
        foreach ((array) ($scope['abc_classes'] ?? []) as $c) {
            \App\Domains\Catalog\Enums\AbcClass::tryFrom($c) ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED,
                "`$c` is not a valid ABC class. Allowed: ".implode(', ', \App\Domains\Catalog\Enums\AbcClass::values()).'.', ['field' => 'scope.abc_classes']);
        }

        return array_filter($scope);
    }
}
