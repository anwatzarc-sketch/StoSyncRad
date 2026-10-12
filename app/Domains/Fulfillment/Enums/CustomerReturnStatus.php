<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Enums;

use App\Support\Enums\HasStateTransitions;
use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\StateEnum;

/** Customer return (RMA) lifecycle. */
enum CustomerReturnStatus: string implements StateEnum
{
    use HasStateTransitions;
    use HasSystemEnum;

    case REQUESTED = 'requested';
    case AUTHORIZED = 'authorized';
    case RECEIVED = 'received';
    case INSPECTED = 'inspected';
    case POSTED = 'posted';
    case REJECTED = 'rejected';
    case CANCELLED = 'cancelled';

    public static function transitions(): array
    {
        return [
            self::REQUESTED->value => [self::AUTHORIZED->value, self::REJECTED->value, self::CANCELLED->value],
            self::AUTHORIZED->value => [self::RECEIVED->value, self::CANCELLED->value],
            self::RECEIVED->value => [self::INSPECTED->value, self::POSTED->value],
            self::INSPECTED->value => [self::POSTED->value, self::REJECTED->value],
            self::POSTED->value => [],
            self::REJECTED->value => [],
            self::CANCELLED->value => [],
        ];
    }
}
