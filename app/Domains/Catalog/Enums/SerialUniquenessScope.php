<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Scope in which serial numbers are unique. */
enum SerialUniquenessScope: string implements SystemEnum
{
    use HasSystemEnum;

    case ITEM = 'item';
    case GLOBAL = 'global';
}
