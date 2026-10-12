<?php

declare(strict_types=1);

namespace App\Domains\Planning\Schemas;

use App\Domains\Organization\Models\Warehouse;
use App\Domains\Planning\Enums\AllocationPlanStatus;
use App\Domains\Planning\Enums\AllocationPolicy;
use App\Support\Enums\Priority;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** HQ allocation plan (Chapter 08 §8.1): distributes central stock across branches; releasing it generates transfers. */
final class AllocationPlanSchema extends TableSchema
{
    public static function table(): string
    {
        return 'allocation_plans';
    }

    public static function stateEnum(): ?string
    {
        return AllocationPlanStatus::class;
    }

    protected static function define(): array
    {
        $locked = array_values(array_diff(AllocationPlanStatus::values(), ['draft']));

        return [
            'document_number' => Property::string(40)->serverControlled()->searchable()->sortable()->label('Plan number'),
            'warehouse_id' => Property::uuid()->requiredOnCreate()->references(Warehouse::class, 'status', ['active'])->immutableAfter(...$locked)->filterable()->label('Source warehouse'),
            'policy' => Property::enum(AllocationPolicy::class)->default(AllocationPolicy::MANUAL->value)->immutableAfter(...$locked)->filterable()->label('Allocation policy'),
            'period_start' => Property::date()->nullable()->immutableAfter(...$locked)->label('Period start'),
            'period_end' => Property::date()->nullable()->after('period_start')->immutableAfter(...$locked)->label('Period end'),
            'document_date' => Property::date()->nullable()->sortable()->label('Date'),
            'priority' => Property::enum(Priority::class)->default(Priority::NORMAL->value)->label('Priority'),
            'reference' => Property::string(120)->nullable()->trim()->label('Reference'),
            'notes' => Property::text(4000)->nullable()->label('Notes'),
            'status' => Property::enum(AllocationPlanStatus::class)->serverControlled()->filterable()->label('Status'),
            'lines' => Property::arrayOf(AllocationPlanLineSchema::class)->nullable()->max(5000)->immutableAfter(...$locked)->virtual()->label('Lines'),
            'version' => Property::integer()->nullable()->min(1)->virtual()->label('Expected version'),
        ];
    }
}
