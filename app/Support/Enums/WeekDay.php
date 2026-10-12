<?php

declare(strict_types=1);

namespace App\Support\Enums;


/** First day of week. */
enum WeekDay: string implements SystemEnum
{
    use HasSystemEnum;

    case MONDAY = 'monday';
    case SUNDAY = 'sunday';
    case SATURDAY = 'saturday';
}
