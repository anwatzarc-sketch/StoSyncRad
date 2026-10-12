<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Documents;

use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Enums\RelocationStatus;
use App\Domains\Inventory\Enums\StockCondition;
use App\Domains\Inventory\Models\Relocation;
use App\Domains\Inventory\Models\RelocationLine;
use App\Domains\Inventory\Posting\MovementLine;
use App\Domains\Inventory\Posting\MovementRequest;
use App\Domains\Organization\Enums\DocumentType;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Math\Decimal as D;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/** Within-warehouse bin moves (Chapter 08 §8.12): draft → posted, value-neutral. */
final class RelocationWorkflow extends InventoryDocumentWorkflow
{
    protected function documentType(): DocumentType
    {
        return DocumentType::RELOCATION;
    }

    protected function headerClass(): string
    {
        return Relocation::class;
    }

    protected function lineClass(): string
    {
        return RelocationLine::class;
    }

    protected function statusEnum(): string
    {
        return RelocationStatus::class;
    }

    protected function permission(string $action, ?Model $doc = null): string
    {
        return $action === 'post' ? 'inventory.relocation.post' : 'inventory.relocation.create';
    }

    protected function requiresApproval(Model $doc): bool
    {
        return false;
    }

    protected function lineRows(Model $doc, array $line, int $i): array
    {
        $this->lines->item($line['item_id'], false);
        $from = $this->storageIn($doc, $line['from_storage_location_id'], "lines.$i.from_storage_location_id");
        $to = $this->storageIn($doc, $line['to_storage_location_id'], "lines.$i.to_storage_location_id");
        if ($from->id === $to->id) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Source and destination are the same location.', ['field' => "lines.$i.to_storage_location_id"]);
        }
        [$serialId, $lotId] = $this->existingSerial($line, $i);

        return [[
            'item_id' => $line['item_id'], 'item_variant_id' => $line['item_variant_id'] ?? null, 'from_storage_location_id' => $from->id,
            'to_storage_location_id' => $to->id, 'inventory_lot_id' => $lotId, 'item_serial_id' => $serialId,
            'stock_condition' => StockCondition::from($line['stock_condition'] ?? StockCondition::AVAILABLE->value), 'quantity' => D::exact($line['quantity']),
        ]];
    }

    protected function movementRequests(Model $doc, Collection $lines, ?UserAccount $actor): array
    {
        $out = $lines->map(fn (RelocationLine $l) => new MovementLine($l->item_id, $l->item_variant_id, $this->locations->forStorageLocation($l->from_storage_location_id)->id,
            $l->stock_condition, $this->locations->forStorageLocation($l->to_storage_location_id)->id, $l->stock_condition, D::exact($l->quantity),
            lotId: $l->inventory_lot_id, serialId: $l->item_serial_id, sourceLineType: 'relocation_line', sourceLineId: $l->id))->all();

        return [new MovementRequest(MovementType::RELOCATION, DocumentType::RELOCATION, $doc->id, $doc->document_number, (string) Str::uuid7(), $out,
            businessDate: $doc->document_date?->toDateString(), warehouseId: $doc->warehouse_id)];
    }
}
