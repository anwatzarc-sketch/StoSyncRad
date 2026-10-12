<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Services;

use App\Domains\Catalog\Models\Item;
use App\Domains\Inventory\Enums\SerialStatus;
use App\Domains\Inventory\Enums\StockCondition;
use App\Domains\Inventory\Models\ItemSerial;
use App\Domains\Inventory\Posting\MovementLine;
use App\Domains\Inventory\Services\InventoryLocationResolver;
use App\Domains\Organization\Enums\InventoryLocationType;
use App\Domains\Organization\Enums\StorageLocationUsage;
use App\Domains\Organization\Models\StorageLocation;
use App\Domains\Organization\Models\Warehouse;
use App\Domains\Platform\Config\PlatformConfig;
use App\Support\Documents\LineQuantities;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Math\Decimal as D;
use Illuminate\Support\Facades\DB;

/**
 * Shared rules for returns from outside our books (internal recipients, customers): returnable
 * quantity, lot/serial identification, resulting condition and destination, and the inbound
 * movement valued at the original outbound cost (Chapter 08 §8.6, §8.9).
 */
final class ReturnLines
{
    public function __construct(
        private readonly LineQuantities $lines,
        private readonly PlatformConfig $config,
        private readonly InventoryLocationResolver $locations,
    ) {}

    /**
     * @param  array<string, mixed>  $input  validated ReturnLineSchema row
     * @param  object  $source  the issued/sold line: item_id, item_variant_id, inventory_lot_id?, item_serial_id?
     * @return array<int, array<string, mixed>> one row per unit for serial-tracked items
     */
    public function resolve(array $input, object $source, string $returnable, Warehouse $warehouse, string $field): array
    {
        $item = $this->lines->item($source->item_id, false);
        $qty = D::exact($input['quantity']);
        if (D::gt($qty, $returnable)) {
            throw DomainException::of(ErrorCode::QUANTITY_EXCEEDS_OUTSTANDING, 'More than was issued/sold (less earlier returns) cannot be returned.',
                ['field' => "$field.quantity", 'returnable' => D::trim($returnable)]);
        }
        $condition = StockCondition::from($input['resulting_condition'] ?? (string) $this->config->get('fulfillment.returns.default_condition'));
        $destination = $this->destination($input['destination_storage_location_id'] ?? null, $condition, $warehouse, $field);
        $lotId = $source->inventory_lot_id ?? null;
        if (! empty($input['lot_number'])) {
            $lotId = DB::table('inventory_lots')->where('item_id', $item->id)->where('lot_number', $input['lot_number'])->value('id')
                ?? throw DomainException::of(ErrorCode::LOT_MISMATCH, "Lot {$input['lot_number']} is not known for {$item->label()}.", ['field' => "$field.lot_number"]);
        }
        $base = ['item_id' => $item->id, 'item_variant_id' => $source->item_variant_id ?? null, 'resulting_condition' => $condition,
            'destination_storage_location_id' => $destination, 'inspection_notes' => $input['inspection_notes'] ?? null];

        if ($item->tracking_mode->requiresSerial()) {
            $serialId = $source->item_serial_id ?? null;
            if ($serialId === null) {
                $number = $input['serial_number'] ?? throw DomainException::of(ErrorCode::TRACKING_MODE_REQUIRES_SERIAL, "{$item->label()} is serial-tracked; scan the serial.", ['field' => "$field.serial_number"]);
                $serialId = ItemSerial::query()->where('item_id', $item->id)->where('serial_number', $number)->value('id')
                    ?? throw DomainException::of(ErrorCode::SERIAL_STATE_INVALID, "Serial $number is not known.", ['field' => "$field.serial_number"]);
            }
            $serial = ItemSerial::query()->findOrFail($serialId);
            if (! in_array($serial->lifecycle_status, [SerialStatus::ISSUED, SerialStatus::SOLD], true)) {
                throw DomainException::of(ErrorCode::SERIAL_STATE_INVALID, "Serial {$serial->serial_number} is {$serial->lifecycle_status->value}; it cannot be returned.", ['field' => "$field.serial_number"]);
            }
            if (! D::eq($qty, '1')) {
                throw DomainException::of(ErrorCode::TRACKING_MODE_REQUIRES_SERIAL, 'Return serialized units one serial per line.', ['field' => "$field.quantity"]);
            }

            return [$base + ['inventory_lot_id' => $serial->inventory_lot_id ?? $lotId, 'item_serial_id' => $serial->id, 'base_quantity' => '1']];
        }
        if ($item->tracking_mode->requiresLot() && $lotId === null) {
            throw DomainException::of(ErrorCode::TRACKING_MODE_REQUIRES_LOT, "{$item->label()} is lot-tracked; enter the lot.", ['field' => "$field.lot_number"]);
        }

        return [$base + ['inventory_lot_id' => $lotId, 'item_serial_id' => null, 'base_quantity' => $qty]];
    }

    /** Inbound movement line: external location → destination bin in the resulting condition, at the original cost. */
    public function movementLine(object $line, InventoryLocationType $from, ?string $unitCost, string $sourceLineType): MovementLine
    {
        return new MovementLine($line->item_id, $line->item_variant_id ?? null, $this->locations->virtual($from)->id, StockCondition::AVAILABLE,
            $this->locations->forStorageLocation($line->destination_storage_location_id)->id, $line->resulting_condition, D::exact($line->base_quantity),
            lotId: $line->inventory_lot_id, serialId: $line->item_serial_id, unitCost: $unitCost, fixedCost: $unitCost !== null,
            sourceLineType: $sourceLineType, sourceLineId: $line->id);
    }

    private function destination(?string $storageLocationId, StockCondition $condition, Warehouse $warehouse, string $field): string
    {
        if ($storageLocationId !== null) {
            $storage = StorageLocation::query()->findOrFail($storageLocationId);
            if ($storage->warehouse_id !== $warehouse->id) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Returns are put away in the receiving warehouse.', ['field' => "$field.destination_storage_location_id"]);
            }

            return $storage->id;
        }
        $usage = match ($condition) {
            StockCondition::AVAILABLE => StorageLocationUsage::STORAGE,
            StockCondition::DAMAGED => StorageLocationUsage::DAMAGED,
            StockCondition::QUARANTINE, StockCondition::BLOCKED, StockCondition::EXPIRED => StorageLocationUsage::QUARANTINE,
            StockCondition::RETURNED_PENDING_INSPECTION => StorageLocationUsage::RETURNS,
        };

        return $warehouse->defaultLocationId($usage) ?? $warehouse->defaultLocationId(StorageLocationUsage::STORAGE)
            ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Choose where the returned stock is put.', ['field' => "$field.destination_storage_location_id"]);
    }
}
