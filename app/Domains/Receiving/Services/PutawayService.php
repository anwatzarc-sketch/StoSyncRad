<?php

declare(strict_types=1);

namespace App\Domains\Receiving\Services;

use App\Domains\Catalog\Models\Item;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Enums\StockCondition;
use App\Domains\Inventory\Posting\InventoryPoster;
use App\Domains\Inventory\Posting\MovementLine;
use App\Domains\Inventory\Posting\MovementRequest;
use App\Domains\Inventory\Services\InventoryLocationResolver;
use App\Domains\Organization\Enums\DocumentType;
use App\Domains\Organization\Enums\PutawayAppliesTo;
use App\Domains\Organization\Models\PutawayRule;
use App\Domains\Organization\Models\StorageLocation;
use App\Domains\Organization\Models\Warehouse;
use App\Domains\Receiving\Enums\PutawayTaskStatus;
use App\Domains\Receiving\Models\PutawayTask;
use App\Support\Documents\ManagesDocuments;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Math\Decimal as D;
use App\Support\Transactions\PostingContext;
use App\Support\Transactions\PostingOutcome;
use App\Support\Transactions\PostingPipeline;

/**
 * Put-away (Chapter 06 §6.4, Chapter 08 §8.3): suggests a bin from put-away rules (item →
 * category → storage category, by priority) and posts the relocation from the receiving area.
 */
final class PutawayService
{
    use ManagesDocuments;

    public function __construct(
        private readonly PostingPipeline $pipeline,
        private readonly InventoryPoster $poster,
        private readonly InventoryLocationResolver $locations,
    ) {}

    public function createTask(Warehouse $warehouse, ?string $goodsReceiptId, ?string $transferReceiptId, Item $item, ?string $variantId, ?string $lotId,
        ?string $serialId, string $fromLocationId, StockCondition $condition, string $quantity): PutawayTask
    {
        return PutawayTask::query()->create([
            'document_number' => $this->nextNumber(DocumentType::RELOCATION, $warehouse->id),
            'warehouse_id' => $warehouse->id, 'goods_receipt_id' => $goodsReceiptId, 'transfer_receipt_id' => $transferReceiptId,
            'item_id' => $item->id, 'item_variant_id' => $variantId, 'inventory_lot_id' => $lotId, 'item_serial_id' => $serialId,
            'from_storage_location_id' => $fromLocationId, 'suggested_storage_location_id' => $this->suggest($warehouse, $item),
            'stock_condition' => $condition, 'quantity' => $quantity, 'status' => PutawayTaskStatus::OPEN, 'version' => 1,
        ]);
    }

    public function suggest(Warehouse $warehouse, Item $item): ?string
    {
        $targets = [[PutawayAppliesTo::ITEM, $item->id], [PutawayAppliesTo::CATEGORY, $item->category_id]];
        if ($item->storage_category_id !== null) {
            $targets[] = [PutawayAppliesTo::STORAGE_CATEGORY, $item->storage_category_id];
        }
        foreach ($targets as [$appliesTo, $targetId]) {
            $rule = PutawayRule::query()->where('warehouse_id', $warehouse->id)->where('applies_to', $appliesTo->value)->where('target_id', $targetId)
                ->where('is_active', true)->orderBy('priority')->first();
            if ($rule !== null) {
                return $rule->destination_location_id;
            }
        }

        return $warehouse->default_storage_location_id;
    }

    /** Post a put-away move (whole or partial quantity) to the chosen bin. */
    public function post(string $taskId, UserAccount $actor, ?string $idempotencyKey, string $toStorageLocationId, ?string $quantity = null): PostingOutcome
    {
        return $this->pipeline->run($actor, 'receiving.putaway.post', $idempotencyKey, ['task' => $taskId, 'to' => $toStorageLocationId, 'qty' => $quantity],
            function (PostingContext $ctx) use ($taskId, $actor, $toStorageLocationId, $quantity) {
                /** @var PutawayTask $task */
                $task = $this->lockDocument(PutawayTask::class, $taskId);
                $this->authorizeWarehouse($actor, 'receiving.putaway.post', $task->warehouse_id);
                if ($task->status !== PutawayTaskStatus::OPEN) {
                    throw DomainException::of(ErrorCode::ALREADY_POSTED, 'This put-away task is already completed or cancelled.');
                }
                $to = StorageLocation::query()->findOrFail($toStorageLocationId);
                if ($to->warehouse_id !== $task->warehouse_id || ! $to->isActive() || ! $to->is_putaway_target) {
                    throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Choose an active put-away location in the same warehouse.');
                }
                $qty = $quantity === null ? D::exact($task->quantity) : D::exact($quantity);
                if (! D::isPositive($qty) || D::gt($qty, $task->quantity)) {
                    throw DomainException::of(ErrorCode::QUANTITY_EXCEEDS_OUTSTANDING, 'Put-away quantity must be positive and not exceed the task quantity.');
                }
                $from = $this->locations->forStorageLocation($task->from_storage_location_id)->id;
                $movement = $this->poster->post(new MovementRequest(MovementType::RELOCATION, DocumentType::RELOCATION, $task->id, $task->document_number,
                    (string) \Illuminate\Support\Str::uuid7(), [
                        new MovementLine($task->item_id, $task->item_variant_id, $from, $task->stock_condition, $this->locations->forStorageLocation($to->id)->id,
                            $task->stock_condition, $qty, lotId: $task->inventory_lot_id, serialId: $task->item_serial_id, sourceLineType: 'putaway_task', sourceLineId: $task->id),
                    ], warehouseId: $task->warehouse_id), $ctx);

                $remaining = D::sub($task->quantity, $qty);
                $task->forceFill(D::isZero($remaining)
                    ? ['status' => PutawayTaskStatus::POSTED, 'to_storage_location_id' => $to->id, 'posted_by' => $actor->id, 'posted_at' => now(), 'stock_movement_id' => $movement->id]
                    : ['quantity' => $remaining])->forceFill(['version' => $task->version + 1])->save();
                $ctx->audit('receiving.putaway.posted', 'putaway_tasks', $task->id, null, ['to' => $to->full_path, 'quantity' => $qty]);

                return new PostingOutcome(['putaway_task_id' => $task->id, 'remaining_quantity' => $remaining, 'stock_movement_id' => $movement->id], 201);
            }, failureAudit: ['receiving.putaway.post_failed', 'putaway_tasks', $taskId]);
    }
}
