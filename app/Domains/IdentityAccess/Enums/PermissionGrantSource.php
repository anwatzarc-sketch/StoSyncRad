<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Whether a role permission was picked directly or implied. */
enum PermissionGrantSource: string implements SystemEnum
{
    use HasSystemEnum;

    case DIRECT = 'direct';
    case IMPLIED = 'implied';
}
