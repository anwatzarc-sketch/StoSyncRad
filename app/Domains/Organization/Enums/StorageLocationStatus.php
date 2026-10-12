<?php

declare(strict_types=1);

namespace App\Domains\Organization\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Storage location lifecycle. */
enum StorageLocationStatus: string implements SystemEnum
{
    use HasSystemEnum;

    case ACTIVE = 'active';
    case BLOCKED = 'blocked';
    case ARCHIVED = 'archived';
}
