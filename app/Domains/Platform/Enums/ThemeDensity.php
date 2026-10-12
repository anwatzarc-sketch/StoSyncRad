<?php

declare(strict_types=1);

namespace App\Domains\Platform\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Brand kit density preset. */
enum ThemeDensity: string implements SystemEnum
{
    use HasSystemEnum;

    case COMPACT = 'compact';
    case COMFORTABLE = 'comfortable';
    case SPACIOUS = 'spacious';
}
