<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Models;

use App\Support\Eloquent\BaseModel;

/** Delivery attempts of one outbox event to one endpoint. */
final class WebhookDelivery extends BaseModel
{
    protected $table = 'webhook_deliveries';

    protected $fillable = ['webhook_endpoint_id', 'outbox_event_id', 'status', 'attempts', 'next_attempt_at', 'last_status_code', 'last_error', 'delivered_at'];

    protected function casts(): array
    {
        return [
            'status' => \App\Domains\Messaging\Enums\WebhookDeliveryStatus::class,
            'next_attempt_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'attempts' => 'integer',
        ];
    }
}
