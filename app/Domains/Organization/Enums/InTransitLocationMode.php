<?php

declare(strict_types=1);

namespace App\Domains\Organization\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** In-transit location granularity (stock.in_transit_location_mode). */
enum InTransitLocationMode: string implements SystemEnum
{
    use HasSystemEnum;

    case PER_LANE = 'per_lane';
    case COMPANY_WIDE = 'company_wide';
}
