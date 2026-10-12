<?php

declare(strict_types=1);

namespace App\Support\Enums;


/** Secondary display calendar. */
enum CalendarSystem: string implements SystemEnum
{
    use HasSystemEnum;

    case NONE = 'none';
    case ETHIOPIAN = 'ethiopian';
}
