<?php

declare(strict_types=1);

namespace App\Domains\Receiving\Enums;

use App\Support\Enums\HasStateTransitions;
use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\StateEnum;

/** Put-away task lifecycle. */
enum PutawayTaskStatus: string implements StateEnum
{
    use HasStateTransitions;
    use HasSystemEnum;

    case OPEN = 'open';
    case POSTED = 'posted';
    case CANCELLED = 'cancelled';

    public static function transitions(): array
    {
        return [
            self::OPEN->value => [self::POSTED->value, self::CANCELLED->value],
            self::POSTED->value => [],
            self::CANCELLED->value => [],
        ];
    }
}
