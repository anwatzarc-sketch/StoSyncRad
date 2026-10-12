<?php

declare(strict_types=1);

namespace App\Domains\Planning\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Convert a replenishment suggestion. */
final class ReplenishmentConvertSchema extends TableSchema
{
    public static function table(): string
    {
        return 'item_reorder_rules';
    }

    protected static function define(): array
    {
        return [
            'quantity' => Property::quantity()->nullable()->positive()->virtual()->label('Quantity (blank = proposed)'),
        ];
    }
}
