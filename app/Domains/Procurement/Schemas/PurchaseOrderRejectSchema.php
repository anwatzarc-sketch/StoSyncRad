<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Reject a purchase order (optionally back to draft for correction). */
final class PurchaseOrderRejectSchema extends TableSchema
{
    public static function table(): string
    {
        return 'purchase_orders';
    }

    protected static function define(): array
    {
        return [
            'reason' => Property::text(2000)->nullable()->trim()->virtual()->label('Reason'),
            'return_to_draft' => Property::boolean()->default(false)->virtual()->label('Return to draft for correction'),
            'version' => Property::integer()->nullable()->min(1)->virtual()->label('Expected version'),
        ];
    }
}
