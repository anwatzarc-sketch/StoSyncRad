<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Enums;

use App\Support\Enums\HasStateTransitions;
use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\StateEnum;

/** Reservation lifecycle. */
enum ReservationStatus: string implements StateEnum
{
    use HasStateTransitions;
    use HasSystemEnum;

    case ACTIVE = 'active';
    case PARTIALLY_CONSUMED = 'partially_consumed';
    case CONSUMED = 'consumed';
    case RELEASED = 'released';
    case EXPIRED = 'expired';

    public static function transitions(): array
    {
        return [
            self::ACTIVE->value => [self::PARTIALLY_CONSUMED->value, self::CONSUMED->value, self::RELEASED->value, self::EXPIRED->value],
            self::PARTIALLY_CONSUMED->value => [self::CONSUMED->value, self::RELEASED->value, self::EXPIRED->value],
            self::CONSUMED->value => [],
            self::RELEASED->value => [],
            self::EXPIRED->value => [],
        ];
    }
}
