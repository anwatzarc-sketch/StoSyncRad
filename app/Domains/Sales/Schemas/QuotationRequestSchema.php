<?php

declare(strict_types=1);

namespace App\Domains\Sales\Schemas;

use App\Domains\CustomerPortal\Schemas\PortalLineSchema;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** A customer quotation request (products and quantities only). */
final class QuotationRequestSchema extends TableSchema
{
    public static function table(): string
    {
        return 'quotations';
    }

    protected static function define(): array
    {
        return [
            'lines' => Property::arrayOf(PortalLineSchema::class)->required()->min(1)->max(1000)->virtual()->label('Products'),
            'note' => Property::text(2000)->nullable()->trim()->virtual()->label('Message'),
            'customer_reference' => Property::string(120)->nullable()->trim()->virtual()->label('Your reference'),
        ];
    }
}
