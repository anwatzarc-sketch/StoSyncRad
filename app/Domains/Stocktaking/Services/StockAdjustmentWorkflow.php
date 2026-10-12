<?php

declare(strict_types=1);

namespace App\Domains\Stocktaking\Services;

use App\Domains\IdentityAccess\Enums\ApprovalAction;
use App\Domains\IdentityAccess\Enums\DocumentAction;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\IdentityAccess\Services\ApprovalService;
use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Enums\StockCondition;
use App\Domains\Inventory\Models\ItemSerial;
use App\Domains\Inventory\Models\StockMovement;
use App\Domains\Inventory\Posting\InventoryPoster;
use App\Domains\Inventory\Posting\MovementLine;
use App\Domains\Inventory\Posting\MovementRequest;
use App\Domains\Inventory\Services\InventoryLocationResolver;
use App\Domains\Inventory\Services\LotService;
use App\Domains\Inventory\Services\ValuationService;
use App\Domains\Organization\Enums\DocumentType;
use App\Domains\Organization\Enums\InventoryLocationType;
use App\Domains\Organization\Enums\WarehouseCapability;
use App\Domains\Organization\Models\StorageLocation;
use App\Domains\Organization\Models\Warehouse;
use App\Domains\Platform\Config\PlatformConfig;
use App\Domains\Stocktaking\Enums\AdjustmentType;
use App\Domains\Stocktaking\Enums\StockAdjustmentStatus as S;
use App\Domains\Stocktaking\Models\StockAdjustment;
use App\Domains\Stocktaking\Models\StockAdjustmentLine;
use App\Support\Documents\LineQuantities;
use App\Support\Documents\ManagesDocuments;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Math\Decimal as D;
use App\Support\Transactions\PostingContext;
use App\Support\Transactions\PostingOutcome;
use App\Support\Transactions\PostingPipeline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Stock adjustments (Chapter 08 §8.11). Increases post `adjustment → position` at the given or
 * current cost; decreases post `position → adjustment | inventory_loss | scrap` and can never take
 * more than the eligible (unreserved) quantity. Creator ≠ approver; approval tiers by value.
 */
final class StockAdjustmentWorkflow
{
    use ManagesDocuments;

    public function __construct(
        private readonly LineQuantities $lines,
        private readonly ApprovalService $approvals,
        private readonly PlatformConfig $config,
        private readonly PostingPipeline $pipeline,
        private readonly InventoryPoster $poster,
        private readonly InventoryLocationResolver $locations,
        private readonly ValuationService $valuation,
        private readonly LotService $lots,
    ) {}

    /** @param  array<string, mixed>  $data  validated against StockAdjustmentSchema */
    public function save(array $data, UserAccount $actor, ?string $id = null): StockAdjustment
    {
        return DB::transaction(function () use ($data, $actor, $id) {
            if ($id === null) {
                $this->authorizeWarehouse($actor, 'stocktaking.adjustment.create', $data['warehouse_id']);
                $warehouse = Warehouse::query()->findOrFail($data['warehouse_id']);
                $warehouse->assertCapability(WarehouseCapability::ADJUST);
                $adj = new StockAdjustment;
                $adj->forceFill([
                    'document_number' => $this->nextNumber(DocumentType::STOCK_ADJUSTMENT, $warehouse->id), 'warehouse_id' => $warehouse->id,
                    'branch_id' => $warehouse->branch_id, 'adjustment_type' => AdjustmentType::from($data['adjustment_type']), 'status' => S::DRAFT,
                    'created_by' => $actor->id, 'document_date' => $data['document_date'] ?? now()->toDateString(),
                ]);
            } else {
                $adj = $this->lockDocument(StockAdjustment::class, $id);
                $this->authorizeWarehouse($actor, 'stocktaking.adjustment.update', $adj->warehouse_id);
                $this->expectVersion($adj, $data['version'] ?? null);
                $this->assertStatus($adj, [S::DRAFT]);
                $adj->forceFill(['version' => $adj->version + 1]);
            }
            $adj->fill(array_intersect_key($data, array_flip(['reason_lookup_value_id', 'reason', 'document_date', 'priority', 'reference', 'notes'])));
            if (trim((string) $adj->reason) === '') {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Every adjustment needs a justification.', ['field' => 'reason']);
            }
            $adj->forceFill(['updated_by' => $actor->id])->save();
            if (array_key_exists('lines', $data)) {
                $this->replaceLines($adj, $data['lines']);
            }
            $this->audit($actor, $id === null ? 'stocktaking.adjustment.created' : 'stocktaking.adjustment.updated', $adj, null,
                ['document_number' => $adj->document_number, 'total_value' => $adj->total_value]);

            return $adj->refresh();
        });
    }

    public function submit(string $id, UserAccount $actor, ?int $version = null): StockAdjustment
    {
        return DB::transaction(function () use ($id, $actor, $version) {
            $adj = $this->lockDocument(StockAdjustment::class, $id);
            $this->authorizeWarehouse($actor, 'stocktaking.adjustment.submit', $adj->warehouse_id);
            $this->expectVersion($adj, $version);
            if (! StockAdjustmentLine::query()->where('stock_adjustment_id', $adj->id)->exists()) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'The adjustment has no lines.');
            }
            $this->transition($adj, S::PENDING_APPROVAL, ['submitted_by' => $actor->id, 'submitted_at' => now()]);
            $this->approvals->record(DocumentType::STOCK_ADJUSTMENT, $adj->id, $actor, ApprovalAction::SUBMITTED);
            $this->audit($actor, 'stocktaking.adjustment.submitted', $adj);
            $this->publish('stocktaking.adjustment.submitted', $adj, ['document_number' => $adj->document_number, 'warehouse_id' => $adj->warehouse_id, 'value' => $adj->total_value]);

            return $adj;
        });
    }

    public function approve(string $id, UserAccount $actor, ?int $version = null, ?string $comment = null, ?string $overrideReason = null): StockAdjustment
    {
        return DB::transaction(function () use ($id, $actor, $version, $comment, $overrideReason) {
            $adj = $this->lockDocument(StockAdjustment::class, $id);
            $this->expectVersion($adj, $version);
            $this->assertStatus($adj, [S::PENDING_APPROVAL]);
            $value = D::trim(D::exact($this->absoluteValue($adj), D::MONEY_SCALE));
            $this->enforceSod(DocumentType::STOCK_ADJUSTMENT, DocumentAction::APPROVE, $this->documentActors($adj, DocumentType::STOCK_ADJUSTMENT), $actor, $adj->id,
                $value, null, $overrideReason);
            $complete = $this->approvals->approve(DocumentType::STOCK_ADJUSTMENT, $adj->id, $actor, $value, null,
                ['warehouse_id' => $adj->warehouse_id, 'branch_id' => $adj->branch_id], $comment);
            if ($complete) {
                $this->transition($adj, S::APPROVED, ['approved_by' => $actor->id, 'approved_at' => now()]);
            } else {
                $adj->forceFill(['version' => $adj->version + 1])->save();
            }
            $this->audit($actor, 'stocktaking.adjustment.approval_recorded', $adj, null, ['fully_approved' => $complete], $comment);

            return $adj;
        });
    }

    public function reject(string $id, UserAccount $actor, ?string $reason, ?int $version = null): StockAdjustment
    {
        return DB::transaction(function () use ($id, $actor, $reason, $version) {
            $adj = $this->lockDocument(StockAdjustment::class, $id);
            $this->authorizeWarehouse($actor, 'stocktaking.adjustment.approve', $adj->warehouse_id);
            $this->expectVersion($adj, $version);
            if (($reason === null || trim($reason) === '') && $this->config->bool('approval.require_comment_on_reject')) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'A reason is required.', ['field' => 'reason']);
            }
            $this->transition($adj, S::REJECTED, ['rejected_by' => $actor->id, 'rejected_at' => now(), 'rejection_reason' => $reason]);
            $this->approvals->record(DocumentType::STOCK_ADJUSTMENT, $adj->id, $actor, ApprovalAction::REJECTED, $reason);
            $this->audit($actor, 'stocktaking.adjustment.rejected', $adj, null, null, $reason);

            return $adj;
        });
    }

    public function cancel(string $id, UserAccount $actor, ?string $reason, ?int $version = null): StockAdjustment
    {
        return DB::transaction(function () use ($id, $actor, $reason, $version) {
            $adj = $this->lockDocument(StockAdjustment::class, $id);
            $this->authorizeWarehouse($actor, 'stocktaking.adjustment.cancel', $adj->warehouse_id);
            $this->expectVersion($adj, $version);
            if ($adj->source_stocktake_id !== null) {
                throw DomainException::of(ErrorCode::POLICY_VIOLATION, 'Stocktake adjustments are managed by their stocktake.');
            }
            $this->transition($adj, S::CANCELLED, ['cancelled_by' => $actor->id, 'cancelled_at' => now(), 'cancellation_reason' => $reason]);
            $this->audit($actor, 'stocktaking.adjustment.cancelled', $adj, null, null, $reason);

            return $adj;
        });
    }

    /** @param  array<string, mixed>  $options  version, override_reason */
    public function post(string $id, UserAccount $actor, ?string $idempotencyKey, array $options = []): PostingOutcome
    {
        return $this->pipeline->run($actor, 'stocktaking.adjustment.post', $idempotencyKey, ['stock_adjustment_id' => $id] + $options,
            function (PostingContext $ctx) use ($id, $actor, $options) {
                $adj = $this->lockDocument(StockAdjustment::class, $id);
                $this->authorizeWarehouse($actor, 'stocktaking.adjustment.post', $adj->warehouse_id);
                $this->expectVersion($adj, $options['version'] ?? null);
                if ($adj->status === S::POSTED) {
                    throw DomainException::alreadyPosted('Stock adjustment', $adj->document_number);
                }
                $this->assertStatus($adj, [S::APPROVED]);
                $this->enforceSod(DocumentType::STOCK_ADJUSTMENT, DocumentAction::POST, $this->documentActors($adj, DocumentType::STOCK_ADJUSTMENT), $actor, $adj->id,
                    null, null, $options['override_reason'] ?? null);
                $movements = $this->postLines($adj, $ctx, $actor);

                return new PostingOutcome(['stock_adjustment_id' => $adj->id, 'document_number' => $adj->document_number, 'status' => S::POSTED->value,
                    'movement_numbers' => array_map(fn (StockMovement $m) => $m->movement_number, $movements)], 201, DocumentType::STOCK_ADJUSTMENT->value, $adj->id);
            }, failureAudit: ['stocktaking.adjustment.post_failed', 'stock_adjustments', $id]);
    }

    /**
     * Posting core, also used by stocktake posting (inside its own pipeline run).
     *
     * @param  array<int, string>  $permittedFrozenStocktakeIds
     * @return array<int, StockMovement>
     */
    public function postLines(StockAdjustment $adj, PostingContext $ctx, ?UserAccount $actor, array $permittedFrozenStocktakeIds = []): array
    {
        Warehouse::query()->findOrFail($adj->warehouse_id)->assertOperational();
        $type = $adj->adjustment_type;
        $outTarget = match ($type) {
            AdjustmentType::LOSS => InventoryLocationType::INVENTORY_LOSS,
            AdjustmentType::SCRAP => InventoryLocationType::SCRAP,
            default => InventoryLocationType::ADJUSTMENT,
        };
        $adjustment = $this->locations->virtual(InventoryLocationType::ADJUSTMENT)->id;
        $target = $this->locations->virtual($outTarget)->id;
        $in = [];
        $out = [];
        foreach (StockAdjustmentLine::query()->where('stock_adjustment_id', $adj->id)->orderBy('line_no')->get() as $line) {
            $position = $this->locations->forStorageLocation($line->storage_location_id)->id;
            $qty = D::exact($line->quantity);
            if (D::isPositive($qty)) {
                $lotId = $line->inventory_lot_id ?? $this->registerLot($adj, $line, $actor);
                $serialId = $line->item_serial_id ?? $this->registerSerial($line, $lotId);
                $in[] = new MovementLine($line->item_id, $line->item_variant_id, $adjustment, StockCondition::AVAILABLE, $position, $line->stock_condition, $qty,
                    lotId: $lotId, serialId: $serialId, unitCost: $line->unit_cost, sourceLineType: 'stock_adjustment_line', sourceLineId: $line->id);
            } else {
                $out[] = new MovementLine($line->item_id, $line->item_variant_id, $position, $line->stock_condition, $target, $line->stock_condition, D::neg($qty),
                    lotId: $line->inventory_lot_id, serialId: $line->item_serial_id, sourceLineType: 'stock_adjustment_line', sourceLineId: $line->id);
            }
        }
        $movements = [];
        $common = fn (MovementType $mt, array $lines) => new MovementRequest($mt, DocumentType::STOCK_ADJUSTMENT, $adj->id, $adj->document_number, (string) Str::uuid7(),
            $lines, businessDate: $adj->document_date?->toDateString(), warehouseId: $adj->warehouse_id, reasonLookupValueId: $adj->reason_lookup_value_id,
            reasonText: $adj->reason, permittedFrozenStocktakeIds: $permittedFrozenStocktakeIds,
            allowLockedLots: true, allowExpired: true);
        if ($out !== []) {
            $movements[] = $this->poster->post($common($type === AdjustmentType::SCRAP ? MovementType::SCRAP : MovementType::ADJUSTMENT_OUT, $out), $ctx);
        }
        if ($in !== []) {
            $movements[] = $this->poster->post($common(MovementType::ADJUSTMENT_IN, $in), $ctx);
        }
        // Record the actual booked values per line.
        $total = '0';
        foreach ($movements as $m) {
            foreach (DB::table('stock_movement_lines')->where('stock_movement_id', $m->id)->get(['source_line_id', 'total_cost_snapshot', 'unit_cost_snapshot', 'source_inventory_location_id']) as $ml) {
                $sign = $ml->source_inventory_location_id === $adjustment ? '1' : '-1';
                $value = D::mul($sign, (string) $ml->total_cost_snapshot, D::MONEY_SCALE);
                DB::update('UPDATE stock_adjustment_lines SET value = value + ?, unit_cost = COALESCE(unit_cost, ?), updated_at = ? WHERE id = ?',
                    [$value, $ml->unit_cost_snapshot, now(), $ml->source_line_id]);
                $total = D::add($total, $value, D::MONEY_SCALE);
            }
        }
        $adj->forceFill(['status' => S::POSTED, 'posted_by' => $actor?->id, 'posted_at' => now(), 'total_value' => $total,
            'stock_movement_id' => $movements[0]->id ?? null, 'version' => $adj->version + 1])->save();
        $ctx->audit('stocktaking.adjustment.posted', 'stock_adjustments', $adj->id, null, ['document_number' => $adj->document_number, 'total_value' => $total,
            'movements' => array_map(fn ($m) => $m->movement_number, $movements)]);
        $ctx->publish('stocktaking.adjustment.posted', 'stock_adjustments', $adj->id, ['document_number' => $adj->document_number, 'warehouse_id' => $adj->warehouse_id,
            'total_value' => $total]);

        return $movements;
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    public function replaceLines(StockAdjustment $adj, array $lines): void
    {
        StockAdjustmentLine::query()->where('stock_adjustment_id', $adj->id)->delete();
        $total = '0';
        foreach (array_values($lines) as $i => $line) {
            $item = $this->lines->item($line['item_id'], false);
            $qty = D::exact($line['quantity']);
            if (D::isZero($qty)) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Adjustment quantities cannot be zero.', ['field' => "lines.$i.quantity"]);
            }
            $storage = StorageLocation::query()->findOrFail($line['storage_location_id']);
            if ($storage->warehouse_id !== $adj->warehouse_id) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'The location belongs to another warehouse.', ['field' => "lines.$i.storage_location_id"]);
            }
            $serialId = null;
            if ($item->tracking_mode->requiresSerial()) {
                if (! D::eq(D::of($qty)->abs(), '1')) {
                    throw DomainException::of(ErrorCode::TRACKING_MODE_REQUIRES_SERIAL, 'Serialized items are adjusted one serial per line.', ['field' => "lines.$i.quantity"]);
                }
                $number = $line['serial_number'] ?? throw DomainException::of(ErrorCode::TRACKING_MODE_REQUIRES_SERIAL, "{$item->label()} needs a serial number.", ['field' => "lines.$i.serial_number"]);
                $serialId = ItemSerial::query()->where('item_id', $item->id)->where('serial_number', $number)->value('id');
                if ($serialId === null && D::isNegative($qty)) {
                    throw DomainException::of(ErrorCode::SERIAL_STATE_INVALID, "Serial $number is not known.", ['field' => "lines.$i.serial_number"]);
                }
            }
            $lotId = $line['inventory_lot_id'] ?? null;
            if ($item->tracking_mode->requiresLot() && $lotId === null && (D::isNegative($qty) || empty($line['lot_number']))) {
                throw DomainException::of(ErrorCode::TRACKING_MODE_REQUIRES_LOT, "{$item->label()} is lot-tracked; select or enter the lot.", ['field' => "lines.$i.inventory_lot_id"]);
            }
            $cost = D::isPositive($qty) ? ($line['unit_cost'] ?? null) : null;
            $estimate = $cost ?? $this->valuation->estimateCost($item->id, $line['item_variant_id'] ?? null, $this->valuation->bucket($this->locations->forStorageLocation($storage->id)));
            $value = D::mul($qty, $estimate, D::MONEY_SCALE);
            $total = D::add($total, $value, D::MONEY_SCALE);
            StockAdjustmentLine::query()->forceCreate([
                'stock_adjustment_id' => $adj->id, 'line_no' => $i + 1, 'item_id' => $item->id, 'item_variant_id' => $line['item_variant_id'] ?? null,
                'storage_location_id' => $storage->id, 'inventory_lot_id' => $lotId, 'item_serial_id' => $serialId,
                'lot_number' => $line['lot_number'] ?? null, 'expiry_date' => $line['expiry_date'] ?? null, 'serial_number' => $line['serial_number'] ?? null,
                'stock_condition' => StockCondition::from($line['stock_condition'] ?? StockCondition::AVAILABLE->value), 'quantity' => $qty,
                'unit_cost' => $cost, 'value' => '0', 'stocktake_line_id' => $line['stocktake_line_id'] ?? null, 'reason' => $line['reason'] ?? null,
            ]);
        }
        $adj->forceFill(['total_value' => $total])->save();
    }

    private function absoluteValue(StockAdjustment $adj): string
    {
        $sum = '0';
        foreach (StockAdjustmentLine::query()->where('stock_adjustment_id', $adj->id)->get() as $line) {
            $cost = $line->unit_cost ?? $this->valuation->estimateCost($line->item_id, $line->item_variant_id,
                $this->valuation->bucket($this->locations->forStorageLocation($line->storage_location_id)));
            $sum = D::add($sum, D::mul(D::of($line->quantity)->abs(), $cost, D::MONEY_SCALE), D::MONEY_SCALE);
        }

        return $sum;
    }

    private function registerLot(StockAdjustment $adj, StockAdjustmentLine $line, ?UserAccount $actor): ?string
    {
        $item = $this->lines->item($line->item_id, false);
        if (! $item->tracking_mode->requiresLot() && $line->lot_number === null) {
            return null;
        }
        $lot = $this->lots->resolveForReceipt($item, $line->item_variant_id, $line->lot_number, null, null, $line->expiry_date?->toDateString(),
            DocumentType::STOCK_ADJUSTMENT->value, $adj->id, $actor?->id);
        $line->forceFill(['inventory_lot_id' => $lot->id])->save();

        return $lot->id;
    }

    private function registerSerial(StockAdjustmentLine $line, ?string $lotId): ?string
    {
        if ($line->serial_number === null) {
            return null;
        }
        $serial = $this->lots->resolveSerial($this->lines->item($line->item_id, false), $line->item_variant_id, $line->serial_number, $lotId, allowReentry: true);
        $line->forceFill(['item_serial_id' => $serial->id])->save();

        return $serial->id;
    }
}
