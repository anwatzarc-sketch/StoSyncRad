<?php

declare(strict_types=1);

namespace App\Domains\Organization\Enums;

use App\Support\Enums\HasStateTransitions;
use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\StateEnum;

/** Warehouse lifecycle (closing blocks inbound; closed requires zero stock). */
enum WarehouseStatus: string implements StateEnum
{
    use HasStateTransitions;
    use HasSystemEnum;

    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
    case CLOSING = 'closing';
    case CLOSED = 'closed';

    public static function transitions(): array
    {
        return [
            self::ACTIVE->value => [self::INACTIVE->value, self::CLOSING->value],
            self::INACTIVE->value => [self::ACTIVE->value, self::CLOSING->value],
            self::CLOSING->value => [self::ACTIVE->value, self::CLOSED->value],
            self::CLOSED->value => [],
        ];
    }
}
