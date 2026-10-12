<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Schemas;

use App\Domains\Inventory\Enums\OpeningBalanceStatus;
use App\Domains\Organization\Models\Warehouse;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Opening balance document (Chapter 07 §7.12): stock brought in from the `opening_balance` virtual location. */
final class OpeningBalanceSchema extends TableSchema
{
    public static function table(): string
    {
        return 'opening_balance_documents';
    }

    public static function stateEnum(): ?string
    {
        return OpeningBalanceStatus::class;
    }

    protected static function define(): array
    {
        $locked = array_values(array_diff(OpeningBalanceStatus::values(), ['draft']));

        return [
            'document_number' => Property::string(40)->serverControlled()->searchable()->sortable()->label('Number'),
            'warehouse_id' => Property::uuid()->requiredOnCreate()->references(Warehouse::class, 'status', ['active', 'closing'])->immutableAfter(...$locked)->filterable()->label('Warehouse'),
            'cutoff_date' => Property::date()->requiredOnCreate()->immutableAfter(...$locked)->label('Cut-off date', 'Business date of the opening stock.'),
            'document_date' => Property::date()->nullable()->sortable()->label('Date'),
            'reference' => Property::string(120)->nullable()->trim()->searchable()->label('Reference'),
            'notes' => Property::text(4000)->nullable()->label('Notes'),
            'status' => Property::enum(OpeningBalanceStatus::class)->serverControlled()->filterable()->label('Status'),
            'lines' => Property::arrayOf(OpeningBalanceLineSchema::class)->requiredOnCreate()->min(1)->max(2000)->immutableAfter(...$locked)->virtual()->label('Lines'),
            'version' => Property::integer()->nullable()->min(1)->virtual()->label('Expected version'),
        ];
    }
}
