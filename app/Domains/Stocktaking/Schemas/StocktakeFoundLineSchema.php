<?php

declare(strict_types=1);

namespace App\Domains\Stocktaking\Schemas;

use App\Domains\Catalog\Models\Item;
use App\Domains\Catalog\Models\ItemVariant;
use App\Domains\Inventory\Models\InventoryLot;
use App\Domains\Organization\Models\StorageLocation;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

final class StocktakeFoundLineSchema extends TableSchema
{
    public static function table(): string
    {
        return 'stocktake_lines';
    }

    protected static function define(): array
    {
        return [
            'item_id' => Property::uuid()->required()->references(Item::class)->label('Item'),
            'item_variant_id' => Property::uuid()->nullable()->references(ItemVariant::class)->label('Variant'),
            'storage_location_id' => Property::uuid()->required()->references(StorageLocation::class)->label('Location'),
            'inventory_lot_id' => Property::uuid()->nullable()->references(InventoryLot::class)->label('Lot'),
            'quantity' => Property::quantity()->required()->positive()->virtual()->label('Counted quantity (base unit)'),
            'notes' => Property::text(1000)->nullable()->label('Notes'),
        ];
    }
}
