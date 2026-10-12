<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Documents;

use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Enums\OpeningBalanceStatus;
use App\Domains\Inventory\Enums\StockCondition;
use App\Domains\Inventory\Models\OpeningBalanceDocument;
use App\Domains\Inventory\Models\OpeningBalanceLine;
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

/**
 * Opening balances (Chapter 07 §7.12): drafted by import or UI, approved (creator ≠ approver),
 * posted once from the `opening_balance` virtual location with the given unit costs. Lots and
 * serials are registered at posting.
 */
final class OpeningBalanceWorkflow extends InventoryDocumentWorkflow
{
    protected function documentType(): DocumentType
    {
        return DocumentType::OPENING_BALANCE;
    }

    protected function headerClass(): string
    {
        return OpeningBalanceDocument::class;
    }

    protected function lineClass(): string
    {
        return OpeningBalanceLine::class;
    }

    protected function statusEnum(): string
    {
        return OpeningBalanceStatus::class;
    }

    protected function permission(string $action, ?Model $doc = null): string
    {
        return match ($action) {
            'approve' => 'inventory.opening_balance.approve',
            'post' => 'inventory.opening_balance.post',
            default => 'inventory.opening_balance.create',
        };
    }

    protected function headerFields(): array
    {
        return ['cutoff_date'];
    }

    protected function lineRows(Model $doc, array $line, int $i): array
    {
        $item = $this->lines->item($line['item_id']);
        $storage = $this->storageIn($doc, $line['storage_location_id'], "lines.$i.storage_location_id");
        $serials = array_values(array_unique(array_filter((array) ($line['serial_numbers'] ?? []))));
        if ($item->tracking_mode->requiresSerial() && (! D::isWhole($line['quantity']) || count($serials) !== (int) D::of($line['quantity'])->toInt())) {
            throw DomainException::of(ErrorCode::TRACKING_MODE_REQUIRES_SERIAL, "{$item->label()} needs one serial number per unit.", ['field' => "lines.$i.serial_numbers"]);
        }
        if ($item->tracking_mode->requiresLot() && empty($line['lot_number'])) {
            throw DomainException::of(ErrorCode::TRACKING_MODE_REQUIRES_LOT, "{$item->label()} is lot-tracked; enter the lot number.", ['field' => "lines.$i.lot_number"]);
        }

        return [[
            'item_id' => $item->id, 'item_variant_id' => $line['item_variant_id'] ?? null, 'storage_location_id' => $storage->id,
            'quantity' => D::exact($line['quantity']), 'unit_cost' => D::exact($line['unit_cost'], D::COST_SCALE),
            'lot_number' => $line['lot_number'] ?? null, 'expiry_date' => $line['expiry_date'] ?? null, 'manufacture_date' => $line['manufacture_date'] ?? null,
            'serial_numbers' => $serials === [] ? null : $serials,
            'stock_condition' => StockCondition::from($line['stock_condition'] ?? StockCondition::AVAILABLE->value),
        ]];
    }

    protected function movementRequests(Model $doc, Collection $lines, ?UserAccount $actor): array
    {
        $from = $this->locations->virtual(InventoryLocationType::OPENING_BALANCE)->id;
        $out = [];
        /** @var OpeningBalanceLine $line */
        foreach ($lines as $line) {
            $item = $this->lines->item($line->item_id, false);
            $to = $this->locations->forStorageLocation($line->storage_location_id)->id;
            $lotId = null;
            if ($item->tracking_mode->requiresLot() || $line->lot_number !== null) {
                $lotId = $this->lots->resolveForReceipt($item, $line->item_variant_id, $line->lot_number, null, $line->manufacture_date?->toDateString(),
                    $line->expiry_date?->toDateString(), DocumentType::OPENING_BALANCE->value, $doc->id, $actor?->id)->id;
            }
            if ($line->serial_numbers) {
                foreach ($line->serial_numbers as $number) {
                    $serial = $this->lots->resolveSerial($item, $line->item_variant_id, $number, $lotId);
                    $out[] = new MovementLine($item->id, $line->item_variant_id, $from, StockCondition::AVAILABLE, $to, $line->stock_condition, '1',
                        lotId: $lotId, serialId: $serial->id, unitCost: $line->unit_cost, sourceLineType: 'opening_balance_line', sourceLineId: $line->id);
                }

                continue;
            }
            $out[] = new MovementLine($item->id, $line->item_variant_id, $from, StockCondition::AVAILABLE, $to, $line->stock_condition, D::exact($line->quantity),
                lotId: $lotId, unitCost: $line->unit_cost, sourceLineType: 'opening_balance_line', sourceLineId: $line->id);
        }

        return [new MovementRequest(MovementType::OPENING_BALANCE, DocumentType::OPENING_BALANCE, $doc->id, $doc->document_number, (string) Str::uuid7(), $out,
            businessDate: $doc->cutoff_date?->toDateString(), warehouseId: $doc->warehouse_id)];
    }
}
