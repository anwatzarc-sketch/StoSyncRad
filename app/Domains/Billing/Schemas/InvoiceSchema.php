<?php

declare(strict_types=1);

namespace App\Domains\Billing\Schemas;

use App\Domains\Billing\Enums\InvoiceStatus;
use App\Domains\Partners\Models\Partner;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Customer invoice. Totals, paid amount and balance are computed by the server. */
final class InvoiceSchema extends TableSchema
{
    public static function table(): string
    {
        return 'invoices';
    }

    public static function stateEnum(): ?string
    {
        return InvoiceStatus::class;
    }

    protected static function define(): array
    {
        return [
            'document_number' => Property::string(40)->serverControlled()->searchable()->sortable()->label('Number'),
            'status' => Property::enum(InvoiceStatus::class)->serverControlled()->filterable()->label('Status'),
            'customer_partner_id' => Property::uuid()->requiredOnCreate()->references(Partner::class)->immutableAfter('issued', 'partially_paid', 'paid', 'void')->filterable()->label('Customer'),
            'sales_order_id' => Property::uuid()->nullable()->serverControlled()->label('Sales order'),
            'currency' => Property::string(3)->nullable()->pattern('/^[A-Z]{3}$/')->label('Currency'),
            'reference' => Property::string(120)->nullable()->trim()->searchable()->label('Reference'),
            'issue_date' => Property::date()->nullable()->serverControlled()->sortable()->label('Issued on'),
            'due_date' => Property::date()->nullable()->sortable()->label('Due date'),
            'tax_rate' => Property::percentage()->serverControlled()->label('Tax rate (%)', 'The line rate when all lines share one, otherwise the effective rate.'),
            'shipment_id' => Property::uuid()->nullable()->serverControlled()->label('Shipment'),
            'subtotal' => Property::money()->serverControlled()->label('Subtotal'),
            'tax_amount' => Property::money()->serverControlled()->label('Tax'),
            'total' => Property::money()->serverControlled()->sortable()->label('Total'),
            'amount_paid' => Property::money()->serverControlled()->label('Paid'),
            'balance_due' => Property::money()->serverControlled()->sortable()->label('Balance due'),
            'notes' => Property::text(4000)->nullable()->label('Notes'),
            'lines' => Property::arrayOf(InvoiceLineSchema::class)->requiredOnCreate()->min(1)->max(500)->virtual()->label('Lines'),
            'version' => Property::integer()->nullable()->min(1)->virtual()->label('Expected version'),
        ];
    }
}
