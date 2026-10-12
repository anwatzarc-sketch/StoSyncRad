<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** How usernames are chosen (identity.username_policy). */
enum UsernamePolicy: string implements SystemEnum
{
    use HasSystemEnum;

    case EMAIL = 'email';
    case CUSTOM = 'custom';
    case EITHER = 'either';
}
