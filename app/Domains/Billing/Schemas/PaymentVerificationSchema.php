<?php

declare(strict_types=1);

namespace App\Domains\Billing\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Finance confirm that the money reached the company account. */
final class PaymentVerificationSchema extends TableSchema
{
    public static function table(): string
    {
        return 'payment_confirmations';
    }

    protected static function define(): array
    {
        return [
            'amount_received' => Property::money()->nullable()->positive()->virtual()->label('Amount received (if different)'),
            'received_on' => Property::date()->nullable()->virtual()->label('Received on'),
            'bank_reference' => Property::string(120)->nullable()->trim()->virtual()->label('Bank statement reference'),
            'allocate' => Property::boolean()->nullable()->virtual()->label('Allocate to the invoice'),
            'comment' => Property::text(2000)->nullable()->trim()->virtual()->label('Comment'),
            'version' => Property::integer()->nullable()->min(1)->virtual()->label('Expected version'),
        ];
    }
}
