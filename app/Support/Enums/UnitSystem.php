<?php

declare(strict_types=1);

namespace App\Support\Enums;


/** Default unit system for weight/volume/length. */
enum UnitSystem: string implements SystemEnum
{
    use HasSystemEnum;

    case METRIC = 'metric';
    case IMPERIAL = 'imperial';
}
