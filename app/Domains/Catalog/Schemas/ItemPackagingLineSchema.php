<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Schemas;

use App\Domains\Platform\Models\LookupValue;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

final class ItemPackagingLineSchema extends TableSchema
{
    public static function table(): string
    {
        return 'item_packagings';
    }

    protected static function define(): array
    {
        return [
            'id' => Property::uuid()->nullable()->virtual(),
            'name' => Property::string(120)->required()->trim()->label('Name'),
            'contained_qty' => Property::quantity()->required()->positive()->label('Contained quantity (base unit)'),
            'packaging_type_lookup_value_id' => Property::uuid()->nullable()->references(LookupValue::class)->label('Packaging type'),
            'weight' => Property::decimal(12, 4)->nullable()->nonNegative()->label('Weight'),
            'length' => Property::decimal(12, 4)->nullable()->nonNegative()->label('Length'),
            'width' => Property::decimal(12, 4)->nullable()->nonNegative()->label('Width'),
            'height' => Property::decimal(12, 4)->nullable()->nonNegative()->label('Height'),
            'is_default_purchase' => Property::boolean()->default(false)->label('Default for purchasing'),
            'is_default_sales' => Property::boolean()->default(false)->label('Default for sales'),
            'is_default_transfer' => Property::boolean()->default(false)->label('Default for transfers'),
        ];
    }
}
