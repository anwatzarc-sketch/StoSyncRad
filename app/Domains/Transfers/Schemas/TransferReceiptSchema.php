<?php

declare(strict_types=1);

namespace App\Domains\Transfers\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** POST /transfers/{id}/receipts — one receipt posting event (Chapter 08 §8.4 step 6). */
final class TransferReceiptSchema extends TableSchema
{
    public static function table(): string
    {
        return 'transfer_receipts';
    }

    protected static function define(): array
    {
        return [
            'lines' => Property::arrayOf(TransferReceiptLineSchema::class)->required()->min(1)->max(2000)->virtual()->label('Received lines'),
            'notes' => Property::text(2000)->nullable()->label('Notes'),
            'override_reason' => Property::text(2000)->nullable()->virtual()->label('SoD override reason'),
            'version' => Property::integer()->nullable()->min(1)->virtual()->label('Expected version'),
        ];
    }
}
