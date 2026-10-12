<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Account lifecycle (Chapter 04 §4.1). */
enum AccountStatus: string implements SystemEnum
{
    use HasSystemEnum;

    case PENDING_ACTIVATION = 'pending_activation';
    case ACTIVE = 'active';
    case LOCKED = 'locked';
    case SUSPENDED = 'suspended';
    case DEACTIVATED = 'deactivated';
}
