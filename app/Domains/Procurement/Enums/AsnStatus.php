<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Enums;

use App\Support\Enums\HasStateTransitions;
use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\StateEnum;

/** Advance shipment notice lifecycle. */
enum AsnStatus: string implements StateEnum
{
    use HasStateTransitions;
    use HasSystemEnum;

    case OPEN = 'open';
    case RECEIVED = 'received';
    case CANCELLED = 'cancelled';

    public static function transitions(): array
    {
        return [
            self::OPEN->value => [self::RECEIVED->value, self::CANCELLED->value],
            self::RECEIVED->value => [],
            self::CANCELLED->value => [],
        ];
    }
}
