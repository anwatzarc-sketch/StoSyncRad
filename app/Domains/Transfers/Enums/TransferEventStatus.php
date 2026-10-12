<?php

declare(strict_types=1);

namespace App\Domains\Transfers\Enums;

use App\Support\Enums\HasStateTransitions;
use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\StateEnum;

/** Dispatch/receipt event status. */
enum TransferEventStatus: string implements StateEnum
{
    use HasStateTransitions;
    use HasSystemEnum;

    case DRAFT = 'draft';
    case POSTED = 'posted';
    case CANCELLED = 'cancelled';

    public static function transitions(): array
    {
        return [
            self::DRAFT->value => [self::POSTED->value, self::CANCELLED->value],
            self::POSTED->value => [],
            self::CANCELLED->value => [],
        ];
    }
}
