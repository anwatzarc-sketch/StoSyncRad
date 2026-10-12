<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Sales behaviour when stock is short. */
enum OutOfStockBehavior: string implements SystemEnum
{
    use HasSystemEnum;

    case BLOCK = 'block';
    case ALLOW_BACKORDER = 'allow_backorder';
    case SHOW_MESSAGE = 'show_message';
}
