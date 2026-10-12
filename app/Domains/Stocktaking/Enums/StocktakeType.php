<?php

declare(strict_types=1);

namespace App\Domains\Stocktaking\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Stocktake type. */
enum StocktakeType: string implements SystemEnum
{
    use HasSystemEnum;

    case FULL = 'full';
    case CYCLE = 'cycle';
    case SPOT = 'spot';
}
