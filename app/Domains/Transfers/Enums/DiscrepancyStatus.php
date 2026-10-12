<?php

declare(strict_types=1);

namespace App\Domains\Transfers\Enums;

use App\Support\Enums\HasStateTransitions;
use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\StateEnum;

/** Discrepancy lifecycle. */
enum DiscrepancyStatus: string implements StateEnum
{
    use HasStateTransitions;
    use HasSystemEnum;

    case OPEN = 'open';
    case INVESTIGATING = 'investigating';
    case RESOLVED = 'resolved';
    case ACCEPTED = 'accepted';

    public static function transitions(): array
    {
        return [
            self::OPEN->value => [self::INVESTIGATING->value, self::RESOLVED->value, self::ACCEPTED->value],
            self::INVESTIGATING->value => [self::RESOLVED->value, self::ACCEPTED->value],
            self::RESOLVED->value => [],
            self::ACCEPTED->value => [],
        ];
    }
}
