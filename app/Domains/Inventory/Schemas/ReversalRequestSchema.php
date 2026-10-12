<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Schemas;

use App\Domains\Platform\Models\LookupValue;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Request the reversal of a posted movement. */
final class ReversalRequestSchema extends TableSchema
{
    public static function table(): string
    {
        return 'stock_movement_reversals';
    }

    protected static function define(): array
    {
        return [
            'reason' => Property::text(2000)->required()->trim()->virtual()->label('Reason'),
            'reason_lookup_value_id' => Property::uuid()->nullable()->references(LookupValue::class)->virtual()->label('Reason code'),
            'lines' => Property::arrayOf(ReversalLineSchema::class)->nullable()->max(5000)->virtual()->label('Lines (blank = everything outstanding)'),
        ];
    }
}
