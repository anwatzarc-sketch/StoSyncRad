<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Schemas;

use App\Domains\Catalog\Models\Item;
use App\Domains\Catalog\Models\ItemVariant;
use App\Domains\Catalog\Models\UnitOfMeasure;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

final class SalesOrderLineSchema extends TableSchema
{
    public static function table(): string
    {
        return 'sales_order_lines';
    }

    protected static function define(): array
    {
        return [
            'item_id' => Property::uuid()->nullable()->references(Item::class, 'status', ['active'])->label('Item'),
            'sku' => Property::string(80)->nullable()->trim()->virtual()->label('SKU', 'Integrations may identify items by SKU or barcode instead of id.'),
            'barcode' => Property::barcode()->nullable()->virtual()->label('Barcode'),
            'item_variant_id' => Property::uuid()->nullable()->references(ItemVariant::class)->label('Variant'),
            'quantity' => Property::quantity()->required()->positive()->label('Quantity'),
            'uom_id' => Property::uuid()->nullable()->references(UnitOfMeasure::class)->label('Unit'),
            'unit_price' => Property::money()->nullable()->nonNegative()->label('Unit price'),
            'notes' => Property::text(1000)->nullable()->label('Notes'),
        ];
    }
}
