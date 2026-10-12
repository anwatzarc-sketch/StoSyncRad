<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Schemas;

use App\Domains\Catalog\Models\UnitOfMeasure;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Item-specific unit conversion: 1 unit = conversion_factor × base unit. */
final class ItemUomConversionLineSchema extends TableSchema
{
    public static function table(): string
    {
        return 'item_uom_conversions';
    }

    protected static function define(): array
    {
        return [
            'id' => Property::uuid()->nullable()->virtual(),
            'uom_id' => Property::uuid()->required()->references(UnitOfMeasure::class, 'status', ['active'])->label('Unit'),
            'conversion_factor' => Property::decimal(20, 10)->required()->positive()->label('Base units per unit'),
            'rounding_precision' => Property::integer()->default(6)->min(0)->max(6)->label('Rounding precision'),
            'is_active' => Property::boolean()->default(true)->label('Active'),
        ];
    }
}
