<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Models;

use App\Domains\Messaging\Enums\NotificationChannel;
use App\Support\Eloquent\BaseModel;
use App\Support\Eloquent\Casts\JsonList;

/** Per-account channel choice for an event type. */
final class NotificationPreference extends BaseModel
{
    protected $table = 'notification_preferences';

    protected $fillable = ['user_account_id', 'event_type', 'channels', 'is_muted'];

    protected function casts(): array
    {
        return [
            'channels' => JsonList::class.':'.NotificationChannel::class,
            'is_muted' => 'boolean',
        ];
    }
}
