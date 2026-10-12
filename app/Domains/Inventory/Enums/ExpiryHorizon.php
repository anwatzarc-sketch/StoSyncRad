<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Expiry bucket for reporting. */
enum ExpiryHorizon: string implements SystemEnum
{
    use HasSystemEnum;

    case EXPIRED = 'expired';
    case WITHIN_7_DAYS = 'within_7_days';
    case WITHIN_30_DAYS = 'within_30_days';
    case WITHIN_90_DAYS = 'within_90_days';
    case WITHIN_WARNING_PERIOD = 'within_warning_period';
    case BEYOND_WARNING = 'beyond_warning';
    case NO_EXPIRY = 'no_expiry';
}
