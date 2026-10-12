<?php

declare(strict_types=1);

namespace App\Domains\Partners\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Partner lifecycle. */
enum PartnerStatus: string implements SystemEnum
{
    use HasSystemEnum;

    case ACTIVE = 'active';
    case ON_HOLD = 'on_hold';
    case BLACKLISTED = 'blacklisted';
    case ARCHIVED = 'archived';
}
