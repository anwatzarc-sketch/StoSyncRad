<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Schemas;

use App\Domains\Catalog\Enums\UomStatus;
use App\Domains\Catalog\Models\UomCategory;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

final class UnitOfMeasureSchema extends TableSchema
{
    public static function table(): string
    {
        return 'units_of_measure';
    }

    protected static function define(): array
    {
        return [
            'uom_category_id' => Property::uuid()->requiredOnCreate()->references(UomCategory::class)->filterable()->label('Category'),
            'code' => Property::string(20)->requiredOnCreate()->trim()->pattern('/^[A-Za-z0-9_.\/-]{1,20}$/')->unique()->searchable()->label('Code'),
            'name' => Property::string(80)->required()->trim()->searchable()->label('Name'),
            'symbol' => Property::string(20)->required()->trim()->label('Symbol'),
            'ratio_to_reference' => Property::decimal(20, 10)->default('1')->positive()->label('Ratio to reference unit', 'How many reference units one of this unit equals.'),
            'rounding_precision' => Property::integer()->default(6)->min(0)->max(6)->label('Rounding precision'),
            'is_reference' => Property::boolean()->default(false)->label('Reference unit'),
            'is_whole_number' => Property::boolean()->default(false)->label('Whole numbers only'),
            'status' => Property::enum(UomStatus::class)->serverControlled()->filterable()->label('Status'),
        ];
    }
}
