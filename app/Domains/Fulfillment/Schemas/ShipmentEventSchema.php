<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Schemas;

use App\Domains\Fulfillment\Enums\ShipmentStatus;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Carrier/status event: POST /shipments/{id}/events (signed carrier webhooks use the same handler). */
final class ShipmentEventSchema extends TableSchema
{
    public static function table(): string
    {
        return 'shipment_events';
    }

    protected static function define(): array
    {
        return [
            'status' => Property::enum(ShipmentStatus::class)->required()->label('Status'),
            'occurred_at' => Property::datetime()->nullable()->label('Occurred at'),
            'tracking_number' => Property::string(120)->nullable()->trim()->virtual()->label('Tracking number'),
            'payload' => Property::json()->nullable()->label('Carrier payload'),
        ];
    }
}
