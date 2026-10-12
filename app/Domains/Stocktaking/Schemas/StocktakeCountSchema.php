<?php

declare(strict_types=1);

namespace App\Domains\Stocktaking\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Count entry: POST /stocktakes/{id}/counts (counts and recounts; found stock as unexpected lines). */
final class StocktakeCountSchema extends TableSchema
{
    public static function table(): string
    {
        return 'stocktake_lines';
    }

    protected static function define(): array
    {
        return [
            'counts' => Property::arrayOf(StocktakeCountLineSchema::class)->nullable()->max(5000)->virtual()->label('Counted lines'),
            'unexpected' => Property::arrayOf(StocktakeFoundLineSchema::class)->nullable()->max(1000)->virtual()->label('Stock found outside the snapshot'),
            'version' => Property::integer()->nullable()->min(1)->virtual()->label('Expected version'),
        ];
    }
}
