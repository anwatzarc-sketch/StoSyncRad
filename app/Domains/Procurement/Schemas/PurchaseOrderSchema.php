<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Schemas;

use App\Domains\Organization\Models\Warehouse;
use App\Domains\Partners\Models\Partner;
use App\Domains\Platform\Models\LookupValue;
use App\Domains\Procurement\Enums\PurchaseOrderStatus;
use App\Domains\Procurement\Models\PurchaseRequest;
use App\Support\Enums\DataClassification;
use App\Support\Enums\Priority;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

final class PurchaseOrderSchema extends TableSchema
{
    public static function table(): string
    {
        return 'purchase_orders';
    }

    public static function stateEnum(): ?string
    {
        return PurchaseOrderStatus::class;
    }

    protected static function define(): array
    {
        $final = ['partially_received', 'fully_received', 'closed', 'cancelled'];
        $cost = DataClassification::COST_SENSITIVE->value;

        return [
            'document_number' => Property::string(40)->serverControlled()->searchable()->sortable()->label('Number'),
            'warehouse_id' => Property::uuid()->requiredOnCreate()->references(Warehouse::class, 'status', ['active'])->immutableAfter(...$final)->filterable()->label('Destination warehouse'),
            'supplier_id' => Property::uuid()->requiredOnCreate()->references(Partner::class, 'status', ['active'])->immutableAfter(...$final)->filterable()->label('Supplier'),
            'purchase_request_id' => Property::uuid()->nullable()->references(PurchaseRequest::class)->immutableAfter(...$final)->label('Purchase request'),
            'document_date' => Property::date()->nullable()->sortable()->label('Order date'),
            'expected_delivery_date' => Property::date()->nullable()->after('document_date')->sortable()->filterable()->label('Expected delivery'),
            'currency' => Property::string(3)->nullable()->pattern('/^[A-Z]{3}$/')->immutableAfter(...$final)->label('Currency'),
            'exchange_rate' => Property::decimal(20, 10)->nullable()->positive()->immutableAfter(...$final)->label('Exchange rate to base'),
            'incoterm_lookup_value_id' => Property::uuid()->nullable()->references(LookupValue::class)->label('Incoterm'),
            'payment_terms_lookup_value_id' => Property::uuid()->nullable()->references(LookupValue::class)->label('Payment terms'),
            'supplier_reference' => Property::string(80)->nullable()->trim()->searchable()->label('Supplier reference'),
            'delivery_notes' => Property::text(4000)->nullable()->label('Delivery notes'),
            'priority' => Property::enum(Priority::class)->default(Priority::NORMAL->value)->label('Priority'),
            'reference' => Property::string(120)->nullable()->trim()->searchable()->label('Reference'),
            'notes' => Property::text(4000)->nullable()->label('Notes'),
            'charges_total' => Property::money()->nullable()->nonNegative()->immutableAfter(...$final)->classification($cost)->label('Additional charges'),
            'status' => Property::enum(PurchaseOrderStatus::class)->serverControlled()->filterable()->label('Status'),
            'subtotal' => Property::money()->serverControlled()->classification($cost)->label('Subtotal'),
            'total' => Property::money()->serverControlled()->classification($cost)->label('Total'),
            'revision' => Property::integer()->serverControlled()->label('Revision'),
            'lines' => Property::arrayOf(PurchaseOrderLineSchema::class)->requiredOnCreate()->min(1)->max(500)->immutableAfter(...$final)->virtual()->label('Lines'),
            'revision_reason' => Property::text(2000)->nullable()->virtual()->label('Revision reason', 'Required when changing an approved order.'),
            'version' => Property::integer()->nullable()->min(1)->virtual()->label('Expected version'),
        ];
    }
}
