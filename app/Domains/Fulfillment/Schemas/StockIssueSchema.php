<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Schemas;

use App\Domains\Fulfillment\Enums\StockIssueStatus;
use App\Domains\Fulfillment\Models\StockRequest;
use App\Domains\Organization\Models\Warehouse;
use App\Domains\Partners\Models\Partner;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/**
 * Stock issue (Chapter 08 §8.6). From an approved request (warehouse/recipient taken from it) or a
 * direct issue (permission + configuration).
 */
final class StockIssueSchema extends TableSchema
{
    public static function table(): string
    {
        return 'stock_issues';
    }

    public static function stateEnum(): ?string
    {
        return StockIssueStatus::class;
    }

    protected static function define(): array
    {
        $locked = ['posted', 'cancelled'];

        return [
            'document_number' => Property::string(40)->serverControlled()->searchable()->sortable()->label('Issue number'),
            'stock_request_id' => Property::uuid()->nullable()->references(StockRequest::class)->immutableAfter(...$locked)->filterable()->label('Stock request'),
            'warehouse_id' => Property::uuid()->nullable()->references(Warehouse::class, 'status', ['active', 'closing'])->immutableAfter(...$locked)->filterable()->label('Warehouse', 'Direct issues only.'),
            'recipient_partner_id' => Property::uuid()->nullable()->references(Partner::class, 'status', ['active'])->label('Recipient'),
            'recipient_name' => Property::string(160)->nullable()->trim()->label('Received by'),
            'is_production_issue' => Property::boolean()->default(false)->writableBy('fulfillment.production.issue')->label('Issue to production'),
            'document_date' => Property::date()->nullable()->sortable()->label('Issue date'),
            'reference' => Property::string(120)->nullable()->trim()->label('Reference'),
            'notes' => Property::text(4000)->nullable()->label('Notes'),
            'status' => Property::enum(StockIssueStatus::class)->serverControlled()->filterable()->label('Status'),
            'lines' => Property::arrayOf(StockIssueLineSchema::class)->requiredOnCreate()->min(1)->max(2000)->immutableAfter(...$locked)->virtual()->label('Lines'),
            'version' => Property::integer()->nullable()->min(1)->virtual()->label('Expected version'),
        ];
    }
}
