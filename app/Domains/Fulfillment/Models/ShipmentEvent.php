<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Models;

use App\Domains\Fulfillment\Enums\ShipmentStatus;
use App\Support\Eloquent\BaseModel;

/** Append-only carrier/status event. */
final class ShipmentEvent extends BaseModel
{
    protected $table = 'shipment_events';

    public $timestamps = false;

    protected $fillable = ['shipment_id', 'status', 'source', 'payload', 'occurred_at', 'created_at'];

    protected function casts(): array
    {
        return [
            'status' => ShipmentStatus::class,
            'payload' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
