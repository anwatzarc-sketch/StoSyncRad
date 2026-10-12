<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Documents;

use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Inventory\Enums\ConditionChangeStatus;
use App\Domains\Inventory\Enums\LotQcStatus;
use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Enums\StockCondition;
use App\Domains\Inventory\Models\ConditionChange;
use App\Domains\Inventory\Models\ConditionChangeLine;
use App\Domains\Inventory\Models\InventoryLot;
use App\Domains\Inventory\Posting\MovementLine;
use App\Domains\Inventory\Posting\MovementRequest;
use App\Domains\Organization\Enums\DocumentType;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Math\Decimal as D;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Condition changes (Chapter 08 §8.12) including quarantine / inspection release. Releasing stock
 * into `available` requires `inventory.quarantine.release`; lots still pending or rejected by QC
 * cannot be released this way (that is `receiving.qc.release`).
 */
final class ConditionChangeWorkflow extends InventoryDocumentWorkflow
{
    private const HELD = [StockCondition::QUARANTINE, StockCondition::BLOCKED, StockCondition::RETURNED_PENDING_INSPECTION];

    protected function documentType(): DocumentType
    {
        return DocumentType::CONDITION_CHANGE;
    }

    protected function headerClass(): string
    {
        return ConditionChange::class;
    }

    protected function lineClass(): string
    {
        return ConditionChangeLine::class;
    }

    protected function statusEnum(): string
    {
        return ConditionChangeStatus::class;
    }

    protected function headerFields(): array
    {
        return ['reason_lookup_value_id', 'reason'];
    }

    protected function permission(string $action, ?Model $doc = null): string
    {
        if ($action === 'post' && $doc !== null && $this->releases($doc)) {
            return 'inventory.quarantine.release';
        }

        return 'inventory.condition.change';
    }

    protected function requiresApproval(Model $doc): bool
    {
        return false;
    }

    protected function lineRows(Model $doc, array $line, int $i): array
    {
        $this->lines->item($line['item_id'], false);
        $from = StockCondition::from($line['from_condition']);
        $to = StockCondition::from($line['to_condition']);
        if ($from === $to) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'The condition does not change.', ['field' => "lines.$i.to_condition"]);
        }
        $storage = $this->storageIn($doc, $line['storage_location_id'], "lines.$i.storage_location_id");
        $target = isset($line['to_storage_location_id']) ? $this->storageIn($doc, $line['to_storage_location_id'], "lines.$i.to_storage_location_id")->id : null;
        [$serialId, $lotId] = $this->existingSerial($line, $i);
        if ($to === StockCondition::AVAILABLE && $lotId !== null) {
            $lot = InventoryLot::query()->findOrFail($lotId);
            if (! in_array($lot->qc_status, [LotQcStatus::RELEASED, LotQcStatus::CONDITIONAL], true)) {
                throw DomainException::of(ErrorCode::LOT_NOT_RELEASED, "Lot {$lot->lot_number} is {$lot->qc_status->value} in quality control; release it through QC.", ['field' => "lines.$i.inventory_lot_id"]);
            }
        }

        return [[
            'item_id' => $line['item_id'], 'item_variant_id' => $line['item_variant_id'] ?? null, 'storage_location_id' => $storage->id,
            'to_storage_location_id' => $target, 'inventory_lot_id' => $lotId, 'item_serial_id' => $serialId,
            'from_condition' => $from, 'to_condition' => $to, 'quantity' => D::exact($line['quantity']),
        ]];
    }

    protected function movementRequests(Model $doc, Collection $lines, ?UserAccount $actor): array
    {
        $out = $lines->map(fn (ConditionChangeLine $l) => new MovementLine($l->item_id, $l->item_variant_id, $this->locations->forStorageLocation($l->storage_location_id)->id,
            $l->from_condition, $this->locations->forStorageLocation($l->to_storage_location_id ?? $l->storage_location_id)->id, $l->to_condition, D::exact($l->quantity),
            lotId: $l->inventory_lot_id, serialId: $l->item_serial_id, sourceLineType: 'condition_change_line', sourceLineId: $l->id))->all();

        return [new MovementRequest(MovementType::CONDITION_CHANGE, DocumentType::CONDITION_CHANGE, $doc->id, $doc->document_number, (string) Str::uuid7(), $out,
            businessDate: $doc->document_date?->toDateString(), warehouseId: $doc->warehouse_id, reasonLookupValueId: $doc->reason_lookup_value_id, reasonText: $doc->reason)];
    }

    private function releases(Model $doc): bool
    {
        return ConditionChangeLine::query()->where('document_id', $doc->id)->where('to_condition', StockCondition::AVAILABLE->value)
            ->whereIn('from_condition', array_map(fn (StockCondition $c) => $c->value, self::HELD))->exists();
    }
}
