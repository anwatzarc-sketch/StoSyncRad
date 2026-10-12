<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Schemas;

use App\Domains\Catalog\Models\Item;
use App\Domains\Catalog\Models\ItemVariant;
use App\Domains\Catalog\Models\UnitOfMeasure;
use App\Domains\Partners\Models\Partner;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

final class PurchaseRequestLineSchema extends TableSchema
{
    public static function table(): string
    {
        return 'purchase_request_lines';
    }

    protected static function define(): array
    {
        return [
            'item_id' => Property::uuid()->required()->references(Item::class, 'status', ['active'])->label('Item'),
            'item_variant_id' => Property::uuid()->nullable()->references(ItemVariant::class)->label('Variant'),
            'quantity' => Property::quantity()->required()->positive()->label('Quantity'),
            'uom_id' => Property::uuid()->nullable()->references(UnitOfMeasure::class, 'status', ['active'])->label('Unit'),
            'estimated_unit_cost' => Property::unitCost()->nullable()->nonNegative()->label('Estimated unit cost'),
            'suggested_supplier_id' => Property::uuid()->nullable()->references(Partner::class)->label('Suggested supplier'),
            'notes' => Property::text(1000)->nullable()->label('Notes'),
        ];
    }
}
