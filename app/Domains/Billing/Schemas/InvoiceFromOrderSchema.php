<?php

declare(strict_types=1);

namespace App\Domains\Billing\Schemas;

use App\Domains\Billing\Enums\InvoiceBasis;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Create a draft invoice from a sales order. */
final class InvoiceFromOrderSchema extends TableSchema
{
    public static function table(): string
    {
        return 'invoices';
    }

    protected static function define(): array
    {
        return [
            'basis' => Property::enum(InvoiceBasis::class)->nullable()->virtual()->label('Invoice', 'ordered: what is still to bill on the order; delivered: what was delivered and not billed yet.'),
        ];
    }
}
