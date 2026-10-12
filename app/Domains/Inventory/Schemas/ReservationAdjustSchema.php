<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Extend or shorten a reservation. */
final class ReservationAdjustSchema extends TableSchema
{
    public static function table(): string
    {
        return 'stock_reservations';
    }

    protected static function define(): array
    {
        return [
            'expires_at' => Property::datetime()->nullable()->virtual()->label('Expires'),
            'reason' => Property::text(2000)->nullable()->trim()->virtual()->label('Reason'),
        ];
    }
}
