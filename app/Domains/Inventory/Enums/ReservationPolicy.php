<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** When stock is reserved (stock.reservation.policy). */
enum ReservationPolicy: string implements SystemEnum
{
    use HasSystemEnum;

    case ON_APPROVAL = 'on_approval';
    case ON_PICK = 'on_pick';
    case OFF = 'off';
}
