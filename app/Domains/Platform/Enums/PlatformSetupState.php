<?php

declare(strict_types=1);

namespace App\Domains\Platform\Enums;

use App\Support\Enums\HasStateTransitions;
use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\StateEnum;

enum PlatformSetupState: string implements StateEnum
{
    use HasStateTransitions;
    use HasSystemEnum;

    case NOT_STARTED = 'not_started';
    case IN_PROGRESS = 'in_progress';
    case COMPLETED = 'completed';
    case REOPENED = 'reopened';

    public static function transitions(): array
    {
        return [
            self::NOT_STARTED->value => [self::IN_PROGRESS->value],
            self::IN_PROGRESS->value => [self::COMPLETED->value],
            self::COMPLETED->value => [self::REOPENED->value],
            self::REOPENED->value => [self::COMPLETED->value],
        ];
    }
}
