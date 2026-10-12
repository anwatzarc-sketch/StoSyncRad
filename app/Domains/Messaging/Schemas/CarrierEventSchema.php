<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Schemas;

use App\Domains\Fulfillment\Enums\ShipmentStatus;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Carrier status event (signed webhook or service-account token). */
final class CarrierEventSchema extends TableSchema
{
    public static function table(): string
    {
        return 'shipments';
    }

    protected static function define(): array
    {
        return [
            'event_id' => Property::string(120)->required()->trim()->virtual()->label('Carrier event id'),
            'status' => Property::enum(ShipmentStatus::class)->required()->virtual()->label('Status'),
            'tracking_number' => Property::string(120)->nullable()->trim()->virtual()->label('Tracking number'),
            'shipment_number' => Property::string(40)->nullable()->trim()->virtual()->label('Shipment number'),
            'location' => Property::string(190)->nullable()->trim()->virtual()->label('Location'),
            'description' => Property::text(1000)->nullable()->trim()->virtual()->label('Description'),
            'occurred_at' => Property::datetime()->nullable()->virtual()->label('Occurred at'),
        ];
    }
}
