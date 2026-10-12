<?php

declare(strict_types=1);

namespace App\Domains\Receiving\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** How a landed cost is spread over a receipt's lines (Chapter 07 §7.6). */
enum LandedCostBasis: string implements SystemEnum
{
    use HasSystemEnum;

    case VALUE = 'value';
    case QUANTITY = 'quantity';
}
