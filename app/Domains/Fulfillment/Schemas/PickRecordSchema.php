<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Record picked quantities. */
final class PickRecordSchema extends TableSchema
{
    public static function table(): string
    {
        return 'pick_list_lines';
    }

    protected static function define(): array
    {
        return [
            'lines' => Property::arrayOf(PickRecordLineSchema::class)->required()->min(1)->max(2000)->virtual()->label('Picks'),
        ];
    }
}
