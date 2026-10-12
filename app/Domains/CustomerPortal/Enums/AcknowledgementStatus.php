<?php

declare(strict_types=1);

namespace App\Domains\CustomerPortal\Enums;

use App\Support\Enums\HasStateTransitions;
use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\StateEnum;

/** Follow-up state of a delivery acknowledgement: recorded (no issues) or open until staff resolve it. */
enum AcknowledgementStatus: string implements StateEnum
{
    use HasStateTransitions;
    use HasSystemEnum;

    case RECORDED = 'recorded';
    case OPEN = 'open';
    case RESOLVED = 'resolved';

    public static function transitions(): array
    {
        return [
            self::RECORDED->value => [],
            self::OPEN->value => [self::RESOLVED->value],
            self::RESOLVED->value => [],
        ];
    }
}
