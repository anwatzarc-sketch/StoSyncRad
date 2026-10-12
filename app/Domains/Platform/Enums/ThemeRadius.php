<?php

declare(strict_types=1);

namespace App\Domains\Platform\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Brand kit corner radius preset. */
enum ThemeRadius: string implements SystemEnum
{
    use HasSystemEnum;

    case NONE = 'none';
    case SMALL = 'small';
    case MEDIUM = 'medium';
    case LARGE = 'large';
}
