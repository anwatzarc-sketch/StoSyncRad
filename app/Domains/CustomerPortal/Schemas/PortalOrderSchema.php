<?php

declare(strict_types=1);

namespace App\Domains\CustomerPortal\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** A customer order from the published catalogue; prices come from the server. */
final class PortalOrderSchema extends TableSchema
{
    public static function table(): string
    {
        return 'sales_orders';
    }

    protected static function define(): array
    {
        return [
            'lines' => Property::arrayOf(PortalLineSchema::class)->required()->min(1)->max(1000)->virtual()->label('Products'),
            'shipping_address' => Property::json()->nullable()->virtual()->label('Delivery address'),
            'customer_reference' => Property::string(120)->nullable()->trim()->virtual()->label('Your reference (PO number)'),
            'notes' => Property::text(2000)->nullable()->trim()->virtual()->label('Notes'),
        ];
    }
}
