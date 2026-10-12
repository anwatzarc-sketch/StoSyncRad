<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Services;

use App\Domains\Catalog\Models\Item;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Inventory\Enums\ReservationReferenceType;
use App\Domains\Inventory\Enums\StockCondition;
use App\Domains\Inventory\Models\ItemSerial;
use App\Domains\Inventory\Posting\PositionKey;
use App\Domains\Inventory\Services\InventoryLocationResolver;
use App\Domains\Inventory\Services\ReservationService;
use App\Domains\Organization\Models\StorageLocation;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Math\Decimal as D;

/**
 * Turns "what the picker scanned" (bin, lot, serials, quantity) into concrete stock positions and
 * secures the matching reservations, shared by issues, counter sales and shipments.
 */
final class PickedStock
{
    public function __construct(
        private readonly InventoryLocationResolver $locations,
        private readonly ReservationService $reservations,
    ) {}

    /**
     * One row per serial for serial-tracked items, otherwise a single row.
     *
     * @param  array<int, string>|null  $serialNumbers
     * @return array<int, array{storage_location_id: string, lot_id: ?string, serial_id: ?string, quantity: string}>
     */
    public function expand(Item $item, string $warehouseId, string $storageLocationId, ?string $lotId, ?array $serialNumbers, string $baseQuantity, string $field): array
    {
        $storage = StorageLocation::query()->findOrFail($storageLocationId);
        if ($storage->warehouse_id !== $warehouseId) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Stock must be picked from the fulfilling warehouse.', ['field' => "$field.storage_location_id"]);
        }
        if (! $item->tracking_mode->requiresSerial()) {
            if ($item->tracking_mode->requiresLot() && $lotId === null) {
                throw DomainException::of(ErrorCode::TRACKING_MODE_REQUIRES_LOT, "{$item->label()} is lot-tracked; scan the lot.", ['field' => "$field.inventory_lot_id"]);
            }

            return [['storage_location_id' => $storage->id, 'lot_id' => $lotId, 'serial_id' => null, 'quantity' => D::exact($baseQuantity)]];
        }
        $serialNumbers = array_values(array_unique(array_filter((array) $serialNumbers, fn ($s) => $s !== null && $s !== '')));
        if (! D::isWhole($baseQuantity) || count($serialNumbers) !== (int) D::of($baseQuantity)->toInt()) {
            throw DomainException::of(ErrorCode::TRACKING_MODE_REQUIRES_SERIAL, "{$item->label()} is serial-tracked; scan one serial per unit.", ['field' => "$field.serial_numbers"]);
        }
        $rows = [];
        foreach ($serialNumbers as $number) {
            $serial = ItemSerial::query()->where('item_id', $item->id)->where('serial_number', $number)->first()
                ?? throw DomainException::of(ErrorCode::SERIAL_STATE_INVALID, "Serial $number is not known for {$item->label()}.", ['field' => "$field.serial_numbers"]);
            if ($lotId !== null && $serial->inventory_lot_id !== null && $serial->inventory_lot_id !== $lotId) {
                throw DomainException::of(ErrorCode::LOT_MISMATCH, "Serial $number belongs to a different lot.", ['field' => "$field.inventory_lot_id"]);
            }
            $rows[] = ['storage_location_id' => $storage->id, 'lot_id' => $serial->inventory_lot_id ?? $lotId, 'serial_id' => $serial->id, 'quantity' => '1'];
        }

        return $rows;
    }

    /** Inventory location of a picked bin. */
    public function location(string $storageLocationId): string
    {
        return $this->locations->forStorageLocation($storageLocationId)->id;
    }

    /**
     * Secure reservations for a picked row and return those to consume (id → qty).
     *
     * @param  array{storage_location_id: string, lot_id: ?string, serial_id: ?string, quantity: string}  $row
     * @return array<string, string>
     */
    public function secure(ReservationReferenceType $type, string $referenceId, string $referenceLineId, string $itemId, ?string $variantId,
        string $warehouseId, array $row, ?UserAccount $actor, bool $reserveUnreserved = false): array
    {
        $key = new PositionKey($itemId, $variantId, $this->location($row['storage_location_id']), $row['lot_id'], StockCondition::AVAILABLE);

        return $this->reservations->secureForPosition($type, $referenceId, $referenceLineId, $key, $warehouseId, $row['quantity'], $actor,
            $row['serial_id'], $reserveUnreserved);
    }
}
