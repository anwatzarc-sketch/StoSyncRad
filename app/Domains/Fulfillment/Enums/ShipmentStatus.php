<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Enums;

use App\Support\Enums\HasStateTransitions;
use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\StateEnum;

/** Shipment lifecycle. */
enum ShipmentStatus: string implements StateEnum
{
    use HasStateTransitions;
    use HasSystemEnum;

    case PACKED = 'packed';
    case HANDED_OVER = 'handed_over';
    case IN_TRANSIT = 'in_transit';
    case DELIVERED = 'delivered';
    case FAILED = 'failed';
    case RETURNED = 'returned';
    case CANCELLED = 'cancelled';

    public static function transitions(): array
    {
        return [
            self::PACKED->value => [self::HANDED_OVER->value, self::CANCELLED->value],
            self::HANDED_OVER->value => [self::IN_TRANSIT->value, self::DELIVERED->value, self::FAILED->value],
            self::IN_TRANSIT->value => [self::DELIVERED->value, self::FAILED->value, self::RETURNED->value],
            self::DELIVERED->value => [],
            self::FAILED->value => [self::IN_TRANSIT->value, self::RETURNED->value],
            self::RETURNED->value => [],
            self::CANCELLED->value => [],
        ];
    }
}
