<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Enums;

use App\Support\Enums\HasStateTransitions;
use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\StateEnum;

/** Recall lifecycle. */
enum RecallStatus: string implements StateEnum
{
    use HasStateTransitions;
    use HasSystemEnum;

    case INITIATED = 'initiated';
    case IN_PROGRESS = 'in_progress';
    case CLOSED = 'closed';

    public static function transitions(): array
    {
        return [
            self::INITIATED->value => [self::IN_PROGRESS->value, self::CLOSED->value],
            self::IN_PROGRESS->value => [self::CLOSED->value],
            self::CLOSED->value => [],
        ];
    }
}
