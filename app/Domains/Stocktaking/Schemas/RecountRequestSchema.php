<?php

declare(strict_types=1);

namespace App\Domains\Stocktaking\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Send stocktake lines back for recount. */
final class RecountRequestSchema extends TableSchema
{
    public static function table(): string
    {
        return 'stocktake_lines';
    }

    protected static function define(): array
    {
        return [
            'line_ids' => Property::arrayOf(Property::uuid())->required()->min(1)->max(5000)->virtual()->label('Lines to recount'),
        ];
    }
}
