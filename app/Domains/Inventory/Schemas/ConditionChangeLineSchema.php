<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Schemas;

use App\Domains\Catalog\Models\Item;
use App\Domains\Catalog\Models\ItemVariant;
use App\Domains\Inventory\Enums\StockCondition;
use App\Domains\Inventory\Models\InventoryLot;
use App\Domains\Organization\Models\StorageLocation;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

final class ConditionChangeLineSchema extends TableSchema
{
    public static function table(): string
    {
        return 'condition_change_lines';
    }

    protected static function define(): array
    {
        return [
            'item_id' => Property::uuid()->required()->references(Item::class)->label('Item'),
            'item_variant_id' => Property::uuid()->nullable()->references(ItemVariant::class)->label('Variant'),
            'storage_location_id' => Property::uuid()->required()->references(StorageLocation::class)->label('Location'),
            'to_storage_location_id' => Property::uuid()->nullable()->references(StorageLocation::class, 'status', ['active'])->label('Move to', 'Optional: put the stock elsewhere at the same time.'),
            'inventory_lot_id' => Property::uuid()->nullable()->references(InventoryLot::class)->label('Lot'),
            'serial_number' => Property::string(120)->nullable()->trim()->virtual()->label('Serial number'),
            'from_condition' => Property::enum(StockCondition::class)->required()->label('From condition'),
            'to_condition' => Property::enum(StockCondition::class)->required()->label('To condition'),
            'quantity' => Property::quantity()->required()->positive()->label('Quantity (base unit)'),
            'notes' => Property::text(1000)->nullable()->label('Notes'),
        ];
    }
}
