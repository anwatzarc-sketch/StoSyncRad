<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

final class LineApprovalItemSchema extends TableSchema
{
    public static function table(): string
    {
        return 'stock_request_lines';
    }

    protected static function define(): array
    {
        return [
            'line_id' => Property::uuid()->required()->label('Line'),
            'approved_quantity' => Property::quantity()->required()->nonNegative()->label('Approved quantity (base unit)'),
        ];
    }
}
