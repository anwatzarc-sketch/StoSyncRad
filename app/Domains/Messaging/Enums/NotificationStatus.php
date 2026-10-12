<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** In-app notification status. */
enum NotificationStatus: string implements SystemEnum
{
    use HasSystemEnum;

    case UNREAD = 'unread';
    case READ = 'read';
    case ARCHIVED = 'archived';
}
