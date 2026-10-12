<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Models;

use App\Support\Eloquent\BaseModel;

/** Per-account channel choice for an event type. */
final class NotificationPreference extends BaseModel
{
    protected $table = 'notification_preferences';

    protected $fillable = ['user_account_id', 'event_type', 'channels', 'is_muted'];

    protected function casts(): array
    {
        return [
            'channels' => \App\Support\Eloquent\Casts\PgTextArray::class.':'.\App\Domains\Messaging\Enums\NotificationChannel::class,
            'is_muted' => 'boolean',
        ];
    }
}
