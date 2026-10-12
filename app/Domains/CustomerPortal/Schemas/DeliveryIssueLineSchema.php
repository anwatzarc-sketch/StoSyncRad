<?php

declare(strict_types=1);

namespace App\Domains\CustomerPortal\Schemas;

use App\Domains\CustomerPortal\Enums\DeliveryIssueType;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** One delivery discrepancy reported by the customer. */
final class DeliveryIssueLineSchema extends TableSchema
{
    public static function table(): string
    {
        return 'delivery_acknowledgement_lines';
    }

    protected static function define(): array
    {
        return [
            'sales_order_line_id' => Property::uuid()->required()->label('Product'),
            'issue' => Property::enum(DeliveryIssueType::class)->required()->label('Issue'),
            'quantity' => Property::quantity()->nullable()->positive()->label('Quantity affected'),
            'note' => Property::text(1000)->nullable()->trim()->label('Details'),
        ];
    }
}
