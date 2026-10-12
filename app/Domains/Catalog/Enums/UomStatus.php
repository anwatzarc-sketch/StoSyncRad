<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Unit lifecycle. */
enum UomStatus: string implements SystemEnum
{
    use HasSystemEnum;

    case ACTIVE = 'active';
    case ARCHIVED = 'archived';
}
