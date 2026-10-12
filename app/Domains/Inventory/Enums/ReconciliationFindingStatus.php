<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Enums;

use App\Support\Enums\HasStateTransitions;
use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\StateEnum;

/** Reconciliation finding lifecycle. */
enum ReconciliationFindingStatus: string implements StateEnum
{
    use HasStateTransitions;
    use HasSystemEnum;

    case OPEN = 'open';
    case INVESTIGATING = 'investigating';
    case REPAIRED = 'repaired';
    case ACCEPTED = 'accepted';

    public static function transitions(): array
    {
        return [
            self::OPEN->value => [self::INVESTIGATING->value, self::REPAIRED->value, self::ACCEPTED->value],
            self::INVESTIGATING->value => [self::REPAIRED->value, self::ACCEPTED->value],
            self::REPAIRED->value => [],
            self::ACCEPTED->value => [],
        ];
    }
}
