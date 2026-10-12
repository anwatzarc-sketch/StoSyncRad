<?php

declare(strict_types=1);

namespace App\Domains\Sales\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Price of one quotation line. */
final class QuotationPriceLineSchema extends TableSchema
{
    public static function table(): string
    {
        return 'quotation_lines';
    }

    protected static function define(): array
    {
        return [
            'line_id' => Property::uuid()->required()->label('Line'),
            'unit_price' => Property::money()->required()->nonNegative()->label('Unit price'),
        ];
    }
}
