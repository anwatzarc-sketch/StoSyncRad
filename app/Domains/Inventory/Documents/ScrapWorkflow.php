<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Documents;

use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Enums\ScrapStatus;
use App\Domains\Inventory\Enums\StockCondition;
use App\Domains\Inventory\Models\ScrapDocument;
use App\Domains\Inventory\Models\ScrapLine;
use App\Domains\Inventory\Posting\MovementLine;
use App\Domains\Inventory\Posting\MovementRequest;
use App\Domains\Organization\Enums\DocumentType;
use App\Domains\Organization\Enums\InventoryLocationType;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Math\Decimal as D;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/** Scrap / disposal: approved, then posted `position → scrap` at the current cost (recalled and expired lots allowed). */
final class ScrapWorkflow extends InventoryDocumentWorkflow
{
    protected function documentType(): DocumentType
    {
        return DocumentType::SCRAP;
    }

    protected function headerClass(): string
    {
        return ScrapDocument::class;
    }

    protected function lineClass(): string
    {
        return ScrapLine::class;
    }

    protected function statusEnum(): string
    {
        return ScrapStatus::class;
    }

    protected function headerFields(): array
    {
        return ['reason_lookup_value_id', 'reason'];
    }

    protected function permission(string $action, ?Model $doc = null): string
    {
        return match ($action) {
            'approve' => 'inventory.scrap.approve',
            'post' => 'inventory.scrap.post',
            default => 'inventory.scrap.create',
        };
    }

    protected function lineRows(Model $doc, array $line, int $i): array
    {
        if (trim((string) $doc->reason) === '') {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Scrapping stock needs a reason.', ['field' => 'reason']);
        }
        $this->lines->item($line['item_id'], false);
        $storage = $this->storageIn($doc, $line['storage_location_id'], "lines.$i.storage_location_id");
        [$serialId, $lotId] = $this->existingSerial($line, $i);

        return [[
            'item_id' => $line['item_id'], 'item_variant_id' => $line['item_variant_id'] ?? null, 'storage_location_id' => $storage->id,
            'inventory_lot_id' => $lotId, 'item_serial_id' => $serialId,
            'stock_condition' => StockCondition::from($line['stock_condition'] ?? StockCondition::DAMAGED->value), 'quantity' => D::exact($line['quantity']),
        ]];
    }

    protected function movementRequests(Model $doc, Collection $lines, ?UserAccount $actor): array
    {
        $scrap = $this->locations->virtual(InventoryLocationType::SCRAP)->id;
        $out = $lines->map(fn (ScrapLine $l) => new MovementLine($l->item_id, $l->item_variant_id, $this->locations->forStorageLocation($l->storage_location_id)->id,
            $l->stock_condition, $scrap, $l->stock_condition, D::exact($l->quantity), lotId: $l->inventory_lot_id, serialId: $l->item_serial_id,
            sourceLineType: 'scrap_line', sourceLineId: $l->id))->all();

        return [new MovementRequest(MovementType::SCRAP, DocumentType::SCRAP, $doc->id, $doc->document_number, (string) Str::uuid7(), $out,
            businessDate: $doc->document_date?->toDateString(), warehouseId: $doc->warehouse_id, reasonLookupValueId: $doc->reason_lookup_value_id,
            reasonText: $doc->reason, allowLockedLots: true, allowExpired: true)];
    }
}
