<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Schemas;

use App\Domains\Inventory\Enums\StockCondition;
use App\Domains\Organization\Models\StorageLocation;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** One inspected return line. */
final class InspectionLineSchema extends TableSchema
{
    public static function table(): string
    {
        return 'internal_return_lines';
    }

    protected static function define(): array
    {
        return [
            'line_id' => Property::uuid()->required()->virtual()->label('Line'),
            'resulting_condition' => Property::enum(StockCondition::class)->nullable()->writableBy('inventory.condition.change')->virtual()->label('Condition'),
            'destination_storage_location_id' => Property::uuid()->nullable()->references(StorageLocation::class, 'status', ['active'])->virtual()->label('Put into'),
            'inspection_notes' => Property::text(1000)->nullable()->virtual()->label('Notes'),
        ];
    }
}
