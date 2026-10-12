<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Notification delivery channel. */
enum NotificationChannel: string implements SystemEnum
{
    use HasSystemEnum;

    case IN_APP = 'in_app';
    case EMAIL = 'email';
    case WEBHOOK = 'webhook';
    case PUSH = 'push';
}
