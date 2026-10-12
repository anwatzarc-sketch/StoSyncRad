<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Schemas;

use App\Domains\Fulfillment\Enums\StockRequestStatus;
use App\Domains\Organization\Models\Warehouse;
use App\Domains\Partners\Models\Partner;
use App\Domains\Platform\Models\LookupValue;
use App\Support\Enums\Priority;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

final class StockRequestSchema extends TableSchema
{
    public static function table(): string
    {
        return 'stock_requests';
    }

    public static function stateEnum(): ?string
    {
        return StockRequestStatus::class;
    }

    protected static function define(): array
    {
        $locked = array_values(array_diff(StockRequestStatus::values(), ['draft']));

        return [
            'document_number' => Property::string(40)->serverControlled()->searchable()->sortable()->label('Request number'),
            'warehouse_id' => Property::uuid()->requiredOnCreate()->references(Warehouse::class, 'status', ['active', 'closing'])->immutableAfter(...$locked)->filterable()->label('From warehouse'),
            'recipient_partner_id' => Property::uuid()->nullable()->references(Partner::class, 'status', ['active'])->filterable()->label('Recipient'),
            'department_lookup_value_id' => Property::uuid()->nullable()->references(LookupValue::class)->filterable()->label('Department'),
            'cost_centre' => Property::string(60)->nullable()->trim()->label('Cost centre / project'),
            'document_date' => Property::date()->nullable()->sortable()->label('Request date'),
            'required_by' => Property::date()->nullable()->after('document_date')->sortable()->label('Required by'),
            'purpose' => Property::text(2000)->nullable()->label('Purpose'),
            'priority' => Property::enum(Priority::class)->default(Priority::NORMAL->value)->filterable()->label('Priority'),
            'reference' => Property::string(120)->nullable()->trim()->searchable()->label('Reference'),
            'notes' => Property::text(4000)->nullable()->label('Notes'),
            'status' => Property::enum(StockRequestStatus::class)->serverControlled()->filterable()->label('Status'),
            'lines' => Property::arrayOf(StockRequestLineSchema::class)->requiredOnCreate()->min(1)->max(2000)->immutableAfter(...$locked)->virtual()->label('Lines'),
            'version' => Property::integer()->nullable()->min(1)->virtual()->label('Expected version'),
        ];
    }
}
