<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Schemas;

use App\Domains\Catalog\Models\Item;
use App\Domains\Catalog\Models\UnitOfMeasure;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

final class ItemKitComponentLineSchema extends TableSchema
{
    public static function table(): string
    {
        return 'item_kit_components';
    }

    protected static function define(): array
    {
        return [
            'component_item_id' => Property::uuid()->required()->references(Item::class, 'status', ['active'])->label('Component'),
            'component_variant_id' => Property::uuid()->nullable()->label('Component variant'),
            'quantity' => Property::quantity()->required()->positive()->label('Quantity'),
            'uom_id' => Property::uuid()->required()->references(UnitOfMeasure::class)->label('Unit'),
        ];
    }
}
