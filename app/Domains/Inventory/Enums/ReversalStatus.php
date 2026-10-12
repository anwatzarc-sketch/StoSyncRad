<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Enums;

use App\Support\Enums\HasStateTransitions;
use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\StateEnum;

/** Reversal request lifecycle (Chapter 07 §7.11). */
enum ReversalStatus: string implements StateEnum
{
    use HasStateTransitions;
    use HasSystemEnum;

    case PENDING_APPROVAL = 'pending_approval';
    case POSTED = 'posted';
    case REJECTED = 'rejected';

    public static function transitions(): array
    {
        return [
            self::PENDING_APPROVAL->value => [self::POSTED->value, self::REJECTED->value],
            self::POSTED->value => [],
            self::REJECTED->value => [],
        ];
    }
}
