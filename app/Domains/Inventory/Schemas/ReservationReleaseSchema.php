<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Release all or part of a reservation. */
final class ReservationReleaseSchema extends TableSchema
{
    public static function table(): string
    {
        return 'stock_reservations';
    }

    protected static function define(): array
    {
        return [
            'quantity' => Property::quantity()->nullable()->positive()->virtual()->label('Quantity (blank = all)'),
            'reason' => Property::text(2000)->nullable()->trim()->virtual()->label('Reason'),
        ];
    }
}
