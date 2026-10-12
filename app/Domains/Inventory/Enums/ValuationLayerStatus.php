<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** FIFO valuation layer status. */
enum ValuationLayerStatus: string implements SystemEnum
{
    use HasSystemEnum;

    case OPEN = 'open';
    case CONSUMED = 'consumed';
    case ADJUSTED = 'adjusted';
}
