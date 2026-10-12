<?php

declare(strict_types=1);

namespace App\Domains\Stocktaking\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

final class StocktakeCountLineSchema extends TableSchema
{
    public static function table(): string
    {
        return 'stocktake_lines';
    }

    protected static function define(): array
    {
        return [
            'line_id' => Property::uuid()->required()->virtual()->label('Line'),
            'quantity' => Property::quantity()->nullable()->nonNegative()->virtual()->label('Counted quantity (base unit)'),
            'excluded' => Property::boolean()->default(false)->virtual()->label('Exclude', 'The position could not be counted (blocked, inaccessible).'),
            'notes' => Property::text(1000)->nullable()->label('Notes'),
        ];
    }
}
