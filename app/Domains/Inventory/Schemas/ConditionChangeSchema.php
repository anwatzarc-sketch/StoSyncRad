<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Schemas;

use App\Domains\Inventory\Enums\ConditionChangeStatus;
use App\Domains\Organization\Models\Warehouse;
use App\Domains\Platform\Models\LookupValue;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Condition change / quarantine release (Chapter 08 §8.12, Chapter 07 §7.2). */
final class ConditionChangeSchema extends TableSchema
{
    public static function table(): string
    {
        return 'condition_changes';
    }

    public static function stateEnum(): ?string
    {
        return ConditionChangeStatus::class;
    }

    protected static function define(): array
    {
        $locked = array_values(array_diff(ConditionChangeStatus::values(), ['draft']));

        return [
            'document_number' => Property::string(40)->serverControlled()->searchable()->sortable()->label('Number'),
            'warehouse_id' => Property::uuid()->requiredOnCreate()->references(Warehouse::class, 'status', ['active', 'closing'])->immutableAfter(...$locked)->filterable()->label('Warehouse'),
            'reason_lookup_value_id' => Property::uuid()->nullable()->references(LookupValue::class)->immutableAfter(...$locked)->label('Reason code'),
            'reason' => Property::text(2000)->nullable()->trim()->immutableAfter(...$locked)->label('Reason'),
            'document_date' => Property::date()->nullable()->sortable()->label('Date'),
            'reference' => Property::string(120)->nullable()->trim()->searchable()->label('Reference'),
            'notes' => Property::text(4000)->nullable()->label('Notes'),
            'status' => Property::enum(ConditionChangeStatus::class)->serverControlled()->filterable()->label('Status'),
            'lines' => Property::arrayOf(ConditionChangeLineSchema::class)->requiredOnCreate()->min(1)->max(2000)->immutableAfter(...$locked)->virtual()->label('Lines'),
            'version' => Property::integer()->nullable()->min(1)->virtual()->label('Expected version'),
        ];
    }
}
