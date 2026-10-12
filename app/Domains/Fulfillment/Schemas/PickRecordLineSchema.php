<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** One picked line. */
final class PickRecordLineSchema extends TableSchema
{
    public static function table(): string
    {
        return 'pick_list_lines';
    }

    protected static function define(): array
    {
        return [
            'line_id' => Property::uuid()->required()->virtual()->label('Line'),
            'picked_quantity' => Property::quantity()->required()->nonNegative()->virtual()->label('Picked quantity'),
            'short_pick_reason' => Property::text(500)->nullable()->virtual()->label('Short-pick reason'),
        ];
    }
}
