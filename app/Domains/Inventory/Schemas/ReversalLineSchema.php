<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** One line to reverse. */
final class ReversalLineSchema extends TableSchema
{
    public static function table(): string
    {
        return 'stock_movement_lines';
    }

    protected static function define(): array
    {
        return [
            'line_id' => Property::uuid()->required()->virtual()->label('Movement line'),
            'quantity' => Property::quantity()->required()->positive()->virtual()->label('Quantity'),
        ];
    }
}
