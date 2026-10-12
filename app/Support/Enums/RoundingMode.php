<?php

declare(strict_types=1);

namespace App\Support\Enums;


/** Rounding mode for money/quantity boundaries (Chapter 05 §5.5). */
enum RoundingMode: string implements SystemEnum
{
    use HasSystemEnum;

    case HALF_UP = 'half_up';
    case HALF_DOWN = 'half_down';
    case HALF_EVEN = 'half_even';
    case CEILING = 'ceiling';
    case FLOOR = 'floor';
    case TRUNCATE = 'truncate';
}
