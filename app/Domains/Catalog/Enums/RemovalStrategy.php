<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Physical picking order. */
enum RemovalStrategy: string implements SystemEnum
{
    use HasSystemEnum;

    case FEFO = 'fefo';
    case FIFO = 'fifo';
    case LIFO = 'lifo';
    case CLOSEST_LOCATION = 'closest_location';
    case INHERIT_CATEGORY = 'inherit_category';
}
