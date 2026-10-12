<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Enums;

use App\Support\Enums\HasStateTransitions;
use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\StateEnum;

/** Sales order lifecycle (Chapter 08 §8.8). */
enum SalesOrderStatus: string implements StateEnum
{
    use HasStateTransitions;
    use HasSystemEnum;

    case DRAFT = 'draft';
    case CONFIRMED = 'confirmed';
    case RESERVED = 'reserved';
    case PICKING = 'picking';
    case PACKED = 'packed';
    case PARTIALLY_DELIVERED = 'partially_delivered';
    case DELIVERED = 'delivered';
    case CANCELLED = 'cancelled';
    case RETURNED = 'returned';

    public static function transitions(): array
    {
        return [
            self::DRAFT->value => [self::CONFIRMED->value, self::CANCELLED->value],
            self::CONFIRMED->value => [self::RESERVED->value, self::PICKING->value, self::PACKED->value, self::PARTIALLY_DELIVERED->value, self::DELIVERED->value, self::CANCELLED->value],
            self::RESERVED->value => [self::PICKING->value, self::PACKED->value, self::PARTIALLY_DELIVERED->value, self::DELIVERED->value, self::CANCELLED->value],
            self::PICKING->value => [self::RESERVED->value, self::PACKED->value, self::PARTIALLY_DELIVERED->value, self::DELIVERED->value, self::CANCELLED->value],
            self::PACKED->value => [self::PARTIALLY_DELIVERED->value, self::DELIVERED->value, self::CANCELLED->value],
            self::PARTIALLY_DELIVERED->value => [self::DELIVERED->value, self::RETURNED->value],
            self::DELIVERED->value => [self::RETURNED->value],
            self::CANCELLED->value => [],
            self::RETURNED->value => [],
        ];
    }
}
