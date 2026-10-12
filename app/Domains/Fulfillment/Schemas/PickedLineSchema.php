<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Schemas;

use App\Domains\Inventory\Models\InventoryLot;
use App\Domains\Organization\Models\StorageLocation;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

final class PickedLineSchema extends TableSchema
{
    public static function table(): string
    {
        return 'shipment_package_lines';
    }

    protected static function define(): array
    {
        return [
            'order_line_id' => Property::uuid()->required()->label('Order line'),
            'storage_location_id' => Property::uuid()->required()->references(StorageLocation::class, 'status', ['active'])->label('Picked from'),
            'inventory_lot_id' => Property::uuid()->nullable()->references(InventoryLot::class)->label('Lot'),
            'serial_numbers' => Property::arrayOf(Property::string(120)->trim())->nullable()->max(2000)->label('Serial numbers'),
            'quantity' => Property::quantity()->required()->positive()->label('Quantity (base unit)'),
        ];
    }
}
