<?php

declare(strict_types=1);

namespace App\Domains\Billing\Schemas;

use App\Domains\Catalog\Models\Item;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** An invoice line. */
final class InvoiceLineSchema extends TableSchema
{
    public static function table(): string
    {
        return 'invoice_lines';
    }

    protected static function define(): array
    {
        return [
            'description' => Property::string(300)->required()->trim()->label('Description'),
            'quantity' => Property::quantity()->required()->positive()->label('Quantity'),
            'unit_price' => Property::money()->required()->nonNegative()->label('Unit price'),
            'item_id' => Property::uuid()->nullable()->references(Item::class)->label('Item'),
            'tax_rate' => Property::percentage()->nullable()->label('Tax rate (%)', 'Blank = the item tax rate or the default rate.'),
            'tax_amount' => Property::money()->serverControlled()->label('Tax'),
            'line_total' => Property::money()->serverControlled()->label('Line total'),
        ];
    }
}
