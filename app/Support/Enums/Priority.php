<?php

declare(strict_types=1);

namespace App\Support\Enums;


/** Document priority. */
enum Priority: string implements SystemEnum
{
    use HasSystemEnum;

    case LOW = 'low';
    case NORMAL = 'normal';
    case HIGH = 'high';
    case URGENT = 'urgent';
}
