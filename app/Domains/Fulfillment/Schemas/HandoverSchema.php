<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Schemas;

use App\Domains\Partners\Models\Partner;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Hand a packed shipment to the carrier. */
final class HandoverSchema extends TableSchema
{
    public static function table(): string
    {
        return 'shipments';
    }

    protected static function define(): array
    {
        return [
            'carrier_partner_id' => Property::uuid()->nullable()->references(Partner::class)->virtual()->label('Carrier'),
            'tracking_number' => Property::string(120)->nullable()->trim()->virtual()->label('Tracking number'),
            'version' => Property::integer()->nullable()->min(1)->virtual()->label('Expected version'),
        ];
    }
}
