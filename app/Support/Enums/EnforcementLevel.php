<?php

declare(strict_types=1);

namespace App\Support\Enums;


/** Generic block/warn/off enforcement switch. */
enum EnforcementLevel: string implements SystemEnum
{
    use HasSystemEnum;

    case BLOCK = 'block';
    case WARN = 'warn';
    case OFF = 'off';
}
