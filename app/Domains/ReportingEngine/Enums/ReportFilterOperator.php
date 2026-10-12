<?php

declare(strict_types=1);

namespace App\Domains\ReportingEngine\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Type-aware filter operators. */
enum ReportFilterOperator: string implements SystemEnum
{
    use HasSystemEnum;

    case EQ = 'eq';
    case NEQ = 'neq';
    case GT = 'gt';
    case GTE = 'gte';
    case LT = 'lt';
    case LTE = 'lte';
    case BETWEEN = 'between';
    case IN = 'in';
    case NOT_IN = 'not_in';
    case CONTAINS = 'contains';
    case STARTS_WITH = 'starts_with';
    case ENDS_WITH = 'ends_with';
    case IS_EMPTY = 'is_empty';
    case IS_NOT_EMPTY = 'is_not_empty';
    case RELATIVE_DATE = 'relative_date';

    /** Number of operand values: 0, 1, 2 (between) or -1 (list). */
    public function arity(): int
    {
        return match ($this) {
            self::IS_EMPTY, self::IS_NOT_EMPTY => 0,
            self::BETWEEN => 2,
            self::IN, self::NOT_IN => -1,
            default => 1,
        };
    }
}
