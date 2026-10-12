<?php

declare(strict_types=1);

namespace App\Domains\Billing\Schemas;

use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Enums\PaymentStatus;
use App\Domains\Partners\Models\Partner;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** A payment recorded by finance after verifying the funds. */
final class PaymentSchema extends TableSchema
{
    public static function table(): string
    {
        return 'payments';
    }

    protected static function define(): array
    {
        return [
            'document_number' => Property::string(40)->serverControlled()->searchable()->sortable()->label('Number'),
            'status' => Property::enum(PaymentStatus::class)->serverControlled()->filterable()->label('Status'),
            'customer_partner_id' => Property::uuid()->required()->references(Partner::class)->filterable()->label('Customer'),
            'amount' => Property::money()->required()->positive()->label('Amount received'),
            'currency' => Property::string(3)->required()->pattern('/^[A-Z]{3}$/')->label('Currency'),
            'received_on' => Property::date()->required()->sortable()->label('Received on'),
            'method' => Property::enum(PaymentMethod::class)->required()->label('Method'),
            'bank_reference' => Property::string(120)->nullable()->trim()->searchable()->label('Bank / transaction reference'),
            'notes' => Property::text(2000)->nullable()->label('Notes'),
        ];
    }
}
