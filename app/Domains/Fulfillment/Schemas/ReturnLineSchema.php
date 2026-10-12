<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Schemas;

use App\Domains\Inventory\Enums\StockCondition;
use App\Domains\Organization\Models\StorageLocation;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

final class ReturnLineSchema extends TableSchema
{
    public static function table(): string
    {
        return 'internal_return_lines';
    }

    protected static function define(): array
    {
        return [
            'source_line_id' => Property::uuid()->required()->label('Issued / ordered line'),
            'serial_number' => Property::string(120)->nullable()->trim()->label('Serial number'),
            'lot_number' => Property::string(80)->nullable()->trim()->label('Lot'),
            'quantity' => Property::quantity()->required()->positive()->label('Quantity (base unit)'),
            'resulting_condition' => Property::enum(StockCondition::class)->nullable()->writableBy('inventory.condition.change')
                ->label('Condition', 'Defaults to returned (pending inspection).'),
            'destination_storage_location_id' => Property::uuid()->nullable()->references(StorageLocation::class, 'status', ['active'])->label('Put into'),
            'inspection_notes' => Property::text(1000)->nullable()->label('Inspection notes'),
        ];
    }
}
