<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

enum PersonStatus: string implements SystemEnum
{
    use HasSystemEnum;

    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
    case LEFT = 'left';
}
