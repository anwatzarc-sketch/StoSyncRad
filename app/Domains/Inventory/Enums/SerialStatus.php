<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Enums;

use App\Support\Enums\HasStateTransitions;
use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\StateEnum;

/** Serial unit lifecycle (Chapter 07 §7.8). */
enum SerialStatus: string implements StateEnum
{
    use HasStateTransitions;
    use HasSystemEnum;

    case REGISTERED = 'registered';
    case IN_STOCK = 'in_stock';
    case RESERVED = 'reserved';
    case IN_TRANSIT = 'in_transit';
    case ISSUED = 'issued';
    case SOLD = 'sold';
    case RETURNED_PENDING_INSPECTION = 'returned_pending_inspection';
    case SCRAPPED = 'scrapped';
    case LOST = 'lost';

    public static function transitions(): array
    {
        return [
            self::REGISTERED->value => [self::IN_STOCK->value],
            self::IN_STOCK->value => [self::RESERVED->value, self::IN_TRANSIT->value, self::ISSUED->value, self::SOLD->value, self::SCRAPPED->value, self::LOST->value],
            self::RESERVED->value => [self::IN_STOCK->value, self::IN_TRANSIT->value, self::ISSUED->value, self::SOLD->value],
            self::IN_TRANSIT->value => [self::IN_STOCK->value, self::LOST->value],
            self::ISSUED->value => [self::RETURNED_PENDING_INSPECTION->value, self::IN_STOCK->value],
            self::SOLD->value => [self::RETURNED_PENDING_INSPECTION->value],
            self::RETURNED_PENDING_INSPECTION->value => [self::IN_STOCK->value, self::SCRAPPED->value],
            self::SCRAPPED->value => [],
            self::LOST->value => [self::IN_STOCK->value],
        ];
    }
}
