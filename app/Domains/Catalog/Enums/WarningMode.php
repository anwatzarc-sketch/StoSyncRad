<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Purchase/sales warning behaviour. */
enum WarningMode: string implements SystemEnum
{
    use HasSystemEnum;

    case NONE = 'none';
    case WARN = 'warn';
    case BLOCK = 'block';
}
