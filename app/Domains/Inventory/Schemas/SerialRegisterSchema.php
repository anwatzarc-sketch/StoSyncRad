<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Schemas;

use App\Domains\Catalog\Models\Item;
use App\Domains\Catalog\Models\ItemVariant;
use App\Domains\Inventory\Models\InventoryLot;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Register a serial number. */
final class SerialRegisterSchema extends TableSchema
{
    public static function table(): string
    {
        return 'item_serials';
    }

    protected static function define(): array
    {
        return [
            'item_id' => Property::uuid()->required()->references(Item::class)->virtual()->label('Item'),
            'item_variant_id' => Property::uuid()->nullable()->references(ItemVariant::class)->virtual()->label('Variant'),
            'serial_number' => Property::string(120)->required()->trim()->pattern('/^[A-Za-z0-9._\/-]{1,120}$/')->virtual()->label('Serial number'),
            'inventory_lot_id' => Property::uuid()->nullable()->references(InventoryLot::class)->virtual()->label('Lot'),
            'warranty_until' => Property::date()->nullable()->virtual()->label('Warranty until'),
        ];
    }
}
