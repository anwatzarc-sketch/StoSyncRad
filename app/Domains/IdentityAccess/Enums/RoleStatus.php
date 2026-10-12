<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Role lifecycle. */
enum RoleStatus: string implements SystemEnum
{
    use HasSystemEnum;

    case ACTIVE = 'active';
    case ARCHIVED = 'archived';
}
