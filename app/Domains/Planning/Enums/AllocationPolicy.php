<?php

declare(strict_types=1);

namespace App\Domains\Planning\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Allocation policy. */
enum AllocationPolicy: string implements SystemEnum
{
    use HasSystemEnum;

    case MANUAL = 'manual';
    case PROPORTIONAL_TO_FORECAST = 'proportional_to_forecast';
    case FILL_TO_MAX = 'fill_to_max';
    case PRIORITY_ORDER = 'priority_order';
}
