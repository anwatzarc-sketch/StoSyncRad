<?php

declare(strict_types=1);

namespace App\Domains\Planning\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Forecast method for planning inputs. */
enum ForecastMethod: string implements SystemEnum
{
    use HasSystemEnum;

    case MOVING_AVERAGE = 'moving_average';
    case WEIGHTED_MOVING_AVERAGE = 'weighted_moving_average';
    case SEASONAL_NAIVE = 'seasonal_naive';
    case MANUAL = 'manual';
}
