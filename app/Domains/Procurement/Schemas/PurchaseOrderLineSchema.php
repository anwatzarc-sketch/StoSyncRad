<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Schemas;

use App\Domains\Catalog\Models\Item;
use App\Domains\Catalog\Models\ItemVariant;
use App\Domains\Catalog\Models\UnitOfMeasure;
use App\Support\Enums\DataClassification;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

final class PurchaseOrderLineSchema extends TableSchema
{
    public static function table(): string
    {
        return 'purchase_order_lines';
    }

    protected static function define(): array
    {
        return [
            'purchase_request_line_id' => Property::uuid()->nullable()->label('Request line'),
            'item_id' => Property::uuid()->required()->references(Item::class, 'status', ['active'])->label('Item'),
            'item_variant_id' => Property::uuid()->nullable()->references(ItemVariant::class)->label('Variant'),
            'quantity' => Property::quantity()->required()->positive()->label('Quantity'),
            'uom_id' => Property::uuid()->nullable()->references(UnitOfMeasure::class, 'status', ['active'])->label('Unit'),
            'unit_price' => Property::unitCost()->required()->nonNegative()->classification(DataClassification::COST_SENSITIVE->value)->label('Unit price'),
            'discount_percent' => Property::percentage()->default('0')->label('Discount %'),
            'tax_percent' => Property::percentage()->default('0')->label('Tax %'),
            'expected_date' => Property::date()->nullable()->label('Expected date'),
            'notes' => Property::text(1000)->nullable()->label('Notes'),
        ];
    }
}
