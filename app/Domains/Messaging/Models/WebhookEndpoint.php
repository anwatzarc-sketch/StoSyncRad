<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Models;

use App\Support\Eloquent\BaseModel;

/** Registered receiver of outbox events (HMAC-signed deliveries). */
final class WebhookEndpoint extends BaseModel
{
    protected $table = 'webhook_endpoints';

    protected $fillable = ['name', 'url', 'secret_encrypted', 'event_types', 'is_active', 'consecutive_failures', 'last_success_at', 'disabled_at', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return [
            'event_types' => \App\Support\Eloquent\Casts\PgTextArray::class,
            'is_active' => 'boolean',
            'last_success_at' => 'immutable_datetime',
            'disabled_at' => 'immutable_datetime',
            'secret_encrypted' => 'encrypted',
        ];
    }
}
