<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Schemas;

use App\Domains\Fulfillment\Enums\SalesChannel;
use App\Domains\Fulfillment\Enums\SalesOrderStatus;
use App\Domains\Organization\Models\Warehouse;
use App\Domains\Partners\Models\Partner;
use App\Support\Enums\Priority;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/**
 * Stock-side sales order (Chapter 08 §8.8–8.9). Prices are reference data for documents; payment,
 * invoicing and pricing engines are external integrations.
 */
final class SalesOrderSchema extends TableSchema
{
    public static function table(): string
    {
        return 'sales_orders';
    }

    public static function stateEnum(): ?string
    {
        return SalesOrderStatus::class;
    }

    protected static function define(): array
    {
        $locked = array_values(array_diff(SalesOrderStatus::values(), ['draft']));

        return [
            'document_number' => Property::string(40)->serverControlled()->searchable()->sortable()->label('Order number'),
            'warehouse_id' => Property::uuid()->requiredOnCreate()->references(Warehouse::class, 'status', ['active', 'closing'])->immutableAfter(...$locked)->filterable()->label('Fulfilling warehouse'),
            'channel' => Property::enum(SalesChannel::class)->default(SalesChannel::RETAIL_COUNTER->value)->immutableAfter(...$locked)->filterable()->label('Channel'),
            'customer_partner_id' => Property::uuid()->nullable()->references(Partner::class, 'status', ['active'])->immutableAfter(...$locked)->filterable()->label('Customer', 'Blank = configured walk-in customer.'),
            'external_source' => Property::string(60)->nullable()->trim()->immutableAfter(...$locked)->filterable()->label('External source'),
            'external_order_id' => Property::string(120)->nullable()->trim()->immutableAfter(...$locked)->searchable()->label('External order id'),
            'shipping_address' => Property::json()->nullable()->label('Shipping address'),
            'currency' => Property::string(3)->nullable()->pattern('/^[A-Z]{3}$/')->label('Currency'),
            'payment_reference' => Property::string(120)->nullable()->trim()->label('Payment reference'),
            'document_date' => Property::date()->nullable()->sortable()->label('Order date'),
            'priority' => Property::enum(Priority::class)->default(Priority::NORMAL->value)->label('Priority'),
            'reference' => Property::string(120)->nullable()->trim()->label('Reference'),
            'notes' => Property::text(4000)->nullable()->label('Notes'),
            'status' => Property::enum(SalesOrderStatus::class)->serverControlled()->filterable()->label('Status'),
            'total' => Property::money()->serverControlled()->label('Total'),
            'lines' => Property::arrayOf(SalesOrderLineSchema::class)->requiredOnCreate()->min(1)->max(1000)->immutableAfter(...$locked)->virtual()->label('Lines'),
            'version' => Property::integer()->nullable()->min(1)->virtual()->label('Expected version'),
        ];
    }
}
