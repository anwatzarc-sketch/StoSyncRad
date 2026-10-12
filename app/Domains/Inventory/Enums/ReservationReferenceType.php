<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** What a reservation is held for. */
enum ReservationReferenceType: string implements SystemEnum
{
    use HasSystemEnum;

    case STOCK_REQUEST = 'stock_request';
    case SALES_ORDER = 'sales_order';
    case TRANSFER_ORDER = 'transfer_order';
    case PICK_LIST = 'pick_list';
    case MANUAL = 'manual';
}
