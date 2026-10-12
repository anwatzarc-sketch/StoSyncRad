<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Schemas;

use App\Domains\Catalog\Models\Item;
use App\Domains\Catalog\Models\ItemVariant;
use App\Domains\Catalog\Models\UnitOfMeasure;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

final class StockRequestLineSchema extends TableSchema
{
    public static function table(): string
    {
        return 'stock_request_lines';
    }

    protected static function define(): array
    {
        return [
            'item_id' => Property::uuid()->required()->references(Item::class, 'status', ['active'])->label('Item'),
            'item_variant_id' => Property::uuid()->nullable()->references(ItemVariant::class)->label('Variant'),
            'quantity' => Property::quantity()->required()->positive()->label('Quantity'),
            'uom_id' => Property::uuid()->nullable()->references(UnitOfMeasure::class)->label('Unit'),
            'notes' => Property::text(1000)->nullable()->label('Notes'),
        ];
    }
}
