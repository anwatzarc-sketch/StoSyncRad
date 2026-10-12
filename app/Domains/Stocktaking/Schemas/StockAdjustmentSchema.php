<?php

declare(strict_types=1);

namespace App\Domains\Stocktaking\Schemas;

use App\Domains\Organization\Models\Warehouse;
use App\Domains\Platform\Models\LookupValue;
use App\Domains\Stocktaking\Enums\AdjustmentType;
use App\Domains\Stocktaking\Enums\StockAdjustmentStatus;
use App\Support\Enums\Priority;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Stock adjustment (Chapter 08 §8.11): signed lines, mandatory justification, approval before posting. */
final class StockAdjustmentSchema extends TableSchema
{
    public static function table(): string
    {
        return 'stock_adjustments';
    }

    public static function stateEnum(): ?string
    {
        return StockAdjustmentStatus::class;
    }

    protected static function define(): array
    {
        $locked = array_values(array_diff(StockAdjustmentStatus::values(), ['draft']));

        return [
            'document_number' => Property::string(40)->serverControlled()->searchable()->sortable()->label('Adjustment number'),
            'warehouse_id' => Property::uuid()->requiredOnCreate()->references(Warehouse::class, 'status', ['active', 'closing'])->immutableAfter(...$locked)->filterable()->label('Warehouse'),
            'adjustment_type' => Property::enum(AdjustmentType::class)->requiredOnCreate()->immutableAfter(...$locked)->filterable()->label('Type'),
            'reason_lookup_value_id' => Property::uuid()->nullable()->references(LookupValue::class)->immutableAfter(...$locked)->label('Reason code'),
            'reason' => Property::text(2000)->requiredOnCreate()->trim()->immutableAfter(...$locked)->label('Reason / justification'),
            'document_date' => Property::date()->nullable()->sortable()->label('Date'),
            'priority' => Property::enum(Priority::class)->default(Priority::NORMAL->value)->label('Priority'),
            'reference' => Property::string(120)->nullable()->trim()->label('Reference'),
            'notes' => Property::text(4000)->nullable()->label('Notes'),
            'status' => Property::enum(StockAdjustmentStatus::class)->serverControlled()->filterable()->label('Status'),
            'total_value' => Property::money()->serverControlled()->label('Value'),
            'lines' => Property::arrayOf(StockAdjustmentLineSchema::class)->requiredOnCreate()->min(1)->max(2000)->immutableAfter(...$locked)->virtual()->label('Lines'),
            'version' => Property::integer()->nullable()->min(1)->virtual()->label('Expected version'),
        ];
    }
}
