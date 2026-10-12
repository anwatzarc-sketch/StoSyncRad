<?php

declare(strict_types=1);

namespace App\Domains\ReportingEngine\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Allowed aggregate functions. */
enum ReportAggregate: string implements SystemEnum
{
    use HasSystemEnum;

    case COUNT = 'count';
    case COUNT_DISTINCT = 'count_distinct';
    case SUM = 'sum';
    case AVG = 'avg';
    case MIN = 'min';
    case MAX = 'max';
}
