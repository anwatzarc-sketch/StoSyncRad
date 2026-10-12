<?php

declare(strict_types=1);

namespace App\Domains\ReportingEngine\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Relative date filter ranges. */
enum RelativeDateRange: string implements SystemEnum
{
    use HasSystemEnum;

    case TODAY = 'today';
    case YESTERDAY = 'yesterday';
    case THIS_WEEK = 'this_week';
    case LAST_WEEK = 'last_week';
    case THIS_MONTH = 'this_month';
    case LAST_MONTH = 'last_month';
    case THIS_QUARTER = 'this_quarter';
    case LAST_QUARTER = 'last_quarter';
    case THIS_YEAR = 'this_year';
    case LAST_YEAR = 'last_year';
    case LAST_7_DAYS = 'last_7_days';
    case LAST_30_DAYS = 'last_30_days';
    case LAST_90_DAYS = 'last_90_days';
    case NEXT_30_DAYS = 'next_30_days';
}
