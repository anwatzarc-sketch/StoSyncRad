<?php

declare(strict_types=1);

namespace App\Domains\Receiving\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Payload of POST /goods-receipts/{id}/postings. */
final class ReceiptPostingSchema extends TableSchema
{
    public static function table(): string
    {
        return 'goods_receipts';
    }

    protected static function define(): array
    {
        return [
            'version' => Property::integer()->nullable()->min(1)->virtual()->label('Expected version'),
            'over_receipt_override' => Property::boolean()->default(false)->writableBy('receiving.over_receipt.override')->virtual()->label('Override over-receipt tolerance'),
            'override_reason' => Property::text(2000)->nullable()->trim()->virtual()->label('Override reason'),
        ];
    }
}
