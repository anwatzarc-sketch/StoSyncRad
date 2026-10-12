<?php

declare(strict_types=1);

namespace App\Domains\Billing\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Set or clear a customer's credit limit. */
final class CreditLimitSchema extends TableSchema
{
    public static function table(): string
    {
        return 'partners';
    }

    protected static function define(): array
    {
        return [
            'credit_limit' => Property::money()->nullable()->nonNegative()->label('Credit limit', 'Blank = no limit. Unpaid invoices plus open orders may not go above it.'),
            'reason' => Property::text(2000)->nullable()->trim()->virtual()->label('Reason'),
        ];
    }
}
