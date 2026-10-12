<?php

declare(strict_types=1);

namespace App\Domains\Billing\Schemas;

use App\Domains\Billing\Models\Invoice;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Allocate part of a payment to an invoice. */
final class PaymentAllocationSchema extends TableSchema
{
    public static function table(): string
    {
        return 'payment_allocations';
    }

    protected static function define(): array
    {
        return [
            'invoice_id' => Property::uuid()->required()->references(Invoice::class)->virtual()->label('Invoice'),
            'amount' => Property::money()->required()->positive()->virtual()->label('Amount'),
        ];
    }
}
