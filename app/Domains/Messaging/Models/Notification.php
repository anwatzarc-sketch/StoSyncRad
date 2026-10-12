<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Models;

use App\Support\Eloquent\BaseModel;

/** In-app notification for one account. */
final class Notification extends BaseModel
{
    protected $table = 'notifications';

    protected $fillable = ['user_account_id', 'event_type', 'title', 'body', 'link', 'data', 'status', 'outbox_event_id', 'read_at'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'status' => \App\Domains\Messaging\Enums\NotificationStatus::class,
            'read_at' => 'immutable_datetime',
        ];
    }
}
