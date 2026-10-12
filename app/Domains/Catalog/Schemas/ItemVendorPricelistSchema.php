<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Schemas;

use App\Domains\Catalog\Models\Item;
use App\Domains\Catalog\Models\ItemVariant;
use App\Domains\Catalog\Models\UnitOfMeasure;
use App\Domains\Partners\Models\Partner;
use App\Support\Enums\DataClassification;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

final class ItemVendorPricelistSchema extends TableSchema
{
    public static function table(): string
    {
        return 'item_vendor_pricelists';
    }

    protected static function define(): array
    {
        $cost = DataClassification::COST_SENSITIVE->value;

        return [
            'item_id' => Property::uuid()->requiredOnCreate()->references(Item::class)->filterable()->label('Item'),
            'item_variant_id' => Property::uuid()->nullable()->references(ItemVariant::class)->label('Variant'),
            'supplier_id' => Property::uuid()->required()->references(Partner::class, 'status', ['active', 'on_hold'])->filterable()->label('Supplier'),
            'vendor_product_code' => Property::string(80)->nullable()->trim()->searchable()->label('Vendor product code'),
            'vendor_product_name' => Property::string(200)->nullable()->trim()->label('Vendor product name'),
            'min_qty' => Property::quantity()->default('0')->nonNegative()->label('Minimum quantity'),
            'unit_price' => Property::unitCost()->required()->nonNegative()->classification($cost)->label('Unit price'),
            'currency' => Property::string(3)->required()->pattern('/^[A-Z]{3}$/')->label('Currency'),
            'uom_id' => Property::uuid()->required()->references(UnitOfMeasure::class)->label('Unit'),
            'valid_from' => Property::date()->nullable()->label('Valid from'),
            'valid_to' => Property::date()->nullable()->after('valid_from')->label('Valid to'),
            'lead_time_days' => Property::integer()->nullable()->min(0)->max(3650)->label('Lead time (days)'),
            'is_preferred' => Property::boolean()->default(false)->filterable()->label('Preferred'),
            'last_purchased_at' => Property::datetime()->nullable()->serverControlled(),
            'last_purchase_price' => Property::unitCost()->nullable()->serverControlled()->classification($cost),
        ];
    }
}
