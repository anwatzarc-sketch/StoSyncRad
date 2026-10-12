<?php

declare(strict_types=1);

namespace App\Domains\CustomerPortal\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** A product and quantity requested by a customer (base unit). Prices are never accepted from customers. */
final class PortalLineSchema extends TableSchema
{
    public static function table(): string
    {
        return 'sales_order_lines';
    }

    protected static function define(): array
    {
        return [
            'item_id' => Property::uuid()->required()->label('Product'),
            'quantity' => Property::quantity()->required()->positive()->label('Quantity'),
            'notes' => Property::text(1000)->nullable()->trim()->label('Notes'),
        ];
    }
}
