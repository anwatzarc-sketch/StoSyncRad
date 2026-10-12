<?php

declare(strict_types=1);

namespace App\Domains\Organization\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Branch lifecycle. */
enum BranchStatus: string implements SystemEnum
{
    use HasSystemEnum;

    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
    case CLOSED = 'closed';
}
