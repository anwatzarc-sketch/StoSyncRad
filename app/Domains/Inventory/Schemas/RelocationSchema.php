<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Schemas;

use App\Domains\Inventory\Enums\RelocationStatus;
use App\Domains\Organization\Models\Warehouse;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Within-warehouse relocation (Chapter 08 §8.12). */
final class RelocationSchema extends TableSchema
{
    public static function table(): string
    {
        return 'relocations';
    }

    public static function stateEnum(): ?string
    {
        return RelocationStatus::class;
    }

    protected static function define(): array
    {
        $locked = array_values(array_diff(RelocationStatus::values(), ['draft']));

        return [
            'document_number' => Property::string(40)->serverControlled()->searchable()->sortable()->label('Number'),
            'warehouse_id' => Property::uuid()->requiredOnCreate()->references(Warehouse::class, 'status', ['active', 'closing'])->immutableAfter(...$locked)->filterable()->label('Warehouse'),
            'document_date' => Property::date()->nullable()->sortable()->label('Date'),
            'reference' => Property::string(120)->nullable()->trim()->searchable()->label('Reference'),
            'notes' => Property::text(4000)->nullable()->label('Notes'),
            'status' => Property::enum(RelocationStatus::class)->serverControlled()->filterable()->label('Status'),
            'lines' => Property::arrayOf(RelocationLineSchema::class)->requiredOnCreate()->min(1)->max(2000)->immutableAfter(...$locked)->virtual()->label('Lines'),
            'version' => Property::integer()->nullable()->min(1)->virtual()->label('Expected version'),
        ];
    }
}
