<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Enums;

use App\Support\Enums\HasStateTransitions;
use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\StateEnum;

/** Pick list lifecycle. */
enum PickListStatus: string implements StateEnum
{
    use HasStateTransitions;
    use HasSystemEnum;

    case OPEN = 'open';
    case IN_PROGRESS = 'in_progress';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';

    public static function transitions(): array
    {
        return [
            self::OPEN->value => [self::IN_PROGRESS->value, self::COMPLETED->value, self::CANCELLED->value],
            self::IN_PROGRESS->value => [self::COMPLETED->value, self::CANCELLED->value],
            self::COMPLETED->value => [],
            self::CANCELLED->value => [],
        ];
    }
}
