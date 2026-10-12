<?php

declare(strict_types=1);

namespace App\Domains\ReportingEngine\Enums;

use App\Support\Enums\HasStateTransitions;
use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\StateEnum;

/** Report run lifecycle. */
enum ReportRunStatus: string implements StateEnum
{
    use HasStateTransitions;
    use HasSystemEnum;

    case QUEUED = 'queued';
    case RUNNING = 'running';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
    case REJECTED = 'rejected';
    case EXPIRED = 'expired';

    public static function transitions(): array
    {
        return [
            self::QUEUED->value => [self::RUNNING->value, self::CANCELLED->value, self::REJECTED->value],
            self::RUNNING->value => [self::COMPLETED->value, self::FAILED->value, self::CANCELLED->value],
            self::COMPLETED->value => [self::EXPIRED->value],
            self::FAILED->value => [],
            self::CANCELLED->value => [],
            self::REJECTED->value => [],
            self::EXPIRED->value => [],
        ];
    }
}
