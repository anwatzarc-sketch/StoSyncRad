<?php

declare(strict_types=1);

namespace App\Domains\Platform\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Brand kit font choice. */
enum FontFamily: string implements SystemEnum
{
    use HasSystemEnum;

    case SYSTEM = 'system';
    case INTER = 'inter';
    case ROBOTO = 'roboto';
    case CUSTOM = 'custom';
}
