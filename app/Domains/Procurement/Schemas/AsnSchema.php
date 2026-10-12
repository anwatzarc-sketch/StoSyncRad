<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Supplier advance shipment notice against a purchase order (feature.asn). */
final class AsnSchema extends TableSchema
{
    public static function table(): string
    {
        return 'advance_shipment_notices';
    }

    protected static function define(): array
    {
        return [
            'supplier_reference' => Property::string(80)->nullable()->trim()->label('Supplier reference'),
            'expected_arrival' => Property::date()->nullable()->label('Expected arrival'),
            'lines' => Property::arrayOf(AsnLineSchema::class)->required()->min(1)->max(500)->virtual()->label('Lines'),
        ];
    }
}
