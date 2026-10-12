<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Schemas;

use App\Domains\Platform\Models\LookupValue;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/**
 * Internal return (against a stock issue) or customer return (against a sales order):
 * POST /stock-issues/{id}/returns, POST /sales-orders/{id}/returns.
 */
final class ReturnSchema extends TableSchema
{
    public static function table(): string
    {
        return 'internal_returns';
    }

    protected static function define(): array
    {
        return [
            'reason_lookup_value_id' => Property::uuid()->nullable()->references(LookupValue::class)->virtual()->label('Reason code'),
            'reason' => Property::text(2000)->nullable()->trim()->virtual()->label('Reason'),
            'refund_reference' => Property::string(120)->nullable()->trim()->virtual()->label('Refund reference'),
            'lines' => Property::arrayOf(ReturnLineSchema::class)->required()->min(1)->max(2000)->virtual()->label('Returned lines'),
            'version' => Property::integer()->nullable()->min(1)->virtual()->label('Expected version'),
        ];
    }
}
