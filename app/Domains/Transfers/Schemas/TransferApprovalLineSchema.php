<?php

declare(strict_types=1);

namespace App\Domains\Transfers\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

final class TransferApprovalLineSchema extends TableSchema
{
    public static function table(): string
    {
        return 'transfer_order_lines';
    }

    protected static function define(): array
    {
        return [
            'transfer_order_line_id' => Property::uuid()->required()->label('Line'),
            'approved_quantity' => Property::quantity()->required()->nonNegative()->label('Approved quantity (base unit)'),
        ];
    }
}
