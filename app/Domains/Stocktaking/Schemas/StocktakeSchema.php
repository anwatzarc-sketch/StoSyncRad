<?php

declare(strict_types=1);

namespace App\Domains\Stocktaking\Schemas;

use App\Domains\Organization\Models\Warehouse;
use App\Domains\Stocktaking\Enums\StocktakeFreezePolicy;
use App\Domains\Stocktaking\Enums\StocktakeStatus;
use App\Domains\Stocktaking\Enums\StocktakeType;
use App\Support\Enums\Priority;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Stocktake header (Chapter 08 §8.10). Scope: {storage_location_ids, category_ids, item_ids, abc_classes}. */
final class StocktakeSchema extends TableSchema
{
    public static function table(): string
    {
        return 'stocktakes';
    }

    public static function stateEnum(): ?string
    {
        return StocktakeStatus::class;
    }

    protected static function define(): array
    {
        $started = array_values(array_diff(StocktakeStatus::values(), ['draft', 'scheduled']));

        return [
            'document_number' => Property::string(40)->serverControlled()->searchable()->sortable()->label('Stocktake number'),
            'warehouse_id' => Property::uuid()->requiredOnCreate()->references(Warehouse::class, 'status', ['active', 'closing'])->immutableAfter(...$started)->filterable()->label('Warehouse'),
            'stocktake_type' => Property::enum(StocktakeType::class)->nullable()->immutableAfter(...$started)->filterable()->label('Type', 'Defaults to the configured type.'),
            'freeze_policy' => Property::enum(StocktakeFreezePolicy::class)->nullable()->immutableAfter(...$started)->label('Freeze policy'),
            'is_blind' => Property::boolean()->nullable()->immutableAfter(...$started)->label('Blind count'),
            'scope' => Property::json()->nullable()->immutableAfter(...$started)->label('Scope', 'Locations, categories, items or ABC classes; empty means the whole warehouse.'),
            'scheduled_for' => Property::date()->nullable()->sortable()->label('Scheduled for'),
            'document_date' => Property::date()->nullable()->sortable()->label('Date'),
            'priority' => Property::enum(Priority::class)->default(Priority::NORMAL->value)->label('Priority'),
            'instructions' => Property::text(4000)->nullable()->label('Counter instructions'),
            'reference' => Property::string(120)->nullable()->trim()->label('Reference'),
            'notes' => Property::text(4000)->nullable()->label('Notes'),
            'status' => Property::enum(StocktakeStatus::class)->serverControlled()->filterable()->label('Status'),
            'version' => Property::integer()->nullable()->min(1)->virtual()->label('Expected version'),
        ];
    }
}
