<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Schemas;

use App\Domains\Organization\Models\Warehouse;
use App\Domains\Procurement\Enums\PurchaseRequestStatus;
use App\Support\Enums\Priority;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

final class PurchaseRequestSchema extends TableSchema
{
    public static function table(): string
    {
        return 'purchase_requests';
    }

    public static function stateEnum(): ?string
    {
        return PurchaseRequestStatus::class;
    }

    protected static function define(): array
    {
        $locked = ['submitted', 'approved', 'partially_ordered', 'ordered', 'rejected', 'cancelled'];

        return [
            'document_number' => Property::string(40)->serverControlled()->searchable()->sortable()->label('Number'),
            'warehouse_id' => Property::uuid()->requiredOnCreate()->references(Warehouse::class, 'status', ['active'])->immutableAfter(...$locked)->filterable()->label('Destination warehouse'),
            'document_date' => Property::date()->nullable()->immutableAfter(...$locked)->sortable()->label('Request date'),
            'required_by' => Property::date()->nullable()->after('document_date')->immutableAfter(...$locked)->sortable()->label('Required by'),
            'justification' => Property::text(4000)->nullable()->immutableAfter(...$locked)->label('Justification'),
            'priority' => Property::enum(Priority::class)->default(Priority::NORMAL->value)->immutableAfter(...$locked)->filterable()->label('Priority'),
            'currency' => Property::string(3)->nullable()->pattern('/^[A-Z]{3}$/')->immutableAfter(...$locked)->label('Currency'),
            'reference' => Property::string(120)->nullable()->trim()->searchable()->label('Reference'),
            'notes' => Property::text(4000)->nullable()->label('Notes'),
            'status' => Property::enum(PurchaseRequestStatus::class)->serverControlled()->filterable()->label('Status'),
            'estimated_total' => Property::money()->serverControlled()->label('Estimated total'),
            'lines' => Property::arrayOf(PurchaseRequestLineSchema::class)->requiredOnCreate()->min(1)->max(500)->immutableAfter(...$locked)->virtual()->label('Lines'),
            'version' => Property::integer()->nullable()->min(1)->virtual()->label('Expected version'),
        ];
    }
}
