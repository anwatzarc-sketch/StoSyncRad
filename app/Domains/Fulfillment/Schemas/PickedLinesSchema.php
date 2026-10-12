<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/**
 * Posting payload that names the exact stock picked for each order line:
 * POST /sales-orders/{id}/deliveries (counter sales) and POST /shipments (packing).
 */
final class PickedLinesSchema extends TableSchema
{
    public static function table(): string
    {
        return 'shipment_package_lines';
    }

    protected static function define(): array
    {
        return [
            'lines' => Property::arrayOf(PickedLineSchema::class)->required()->min(1)->max(2000)->virtual()->label('Picked lines'),
            'carrier_partner_id' => Property::uuid()->nullable()->virtual()->label('Carrier'),
            'tracking_number' => Property::string(120)->nullable()->trim()->virtual()->label('Tracking number'),
            'package_weight' => Property::decimal(12, 4)->nullable()->nonNegative()->virtual()->label('Package weight'),
            'notes' => Property::text(1000)->nullable()->virtual()->label('Notes'),
            'version' => Property::integer()->nullable()->min(1)->virtual()->label('Expected version'),
        ];
    }
}
