<?php

declare(strict_types=1);

namespace App\Domains\Billing\Schemas;

use App\Domains\Billing\Enums\PaymentMethod;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** A customer payment confirmation (multipart: evidence files are checked separately). */
final class PaymentConfirmationSchema extends TableSchema
{
    public static function table(): string
    {
        return 'payment_confirmations';
    }

    protected static function define(): array
    {
        return [
            'invoice_id' => Property::uuid()->nullable()->virtual()->label('Invoice'),
            'sales_order_id' => Property::uuid()->nullable()->virtual()->label('Order'),
            'amount' => Property::money()->required()->positive()->virtual()->label('Amount paid'),
            'paid_on' => Property::date()->required()->virtual()->label('Paid on'),
            'method' => Property::enum(PaymentMethod::class)->required()->virtual()->label('How you paid'),
            'transaction_reference' => Property::string(120)->required()->trim()->virtual()->label('Transaction reference'),
            'payer_name' => Property::string(200)->nullable()->trim()->virtual()->label('Paid by (name on the account)'),
            'bank_name' => Property::string(120)->nullable()->trim()->virtual()->label('Bank or mobile wallet'),
            'note' => Property::text(2000)->nullable()->trim()->virtual()->label('Note'),
            'evidence' => Property::any()->nullable()->virtual()->label('Evidence files (checked by the upload handler)'),
        ];
    }
}
