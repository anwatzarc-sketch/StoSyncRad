<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Document a pick list serves. */
enum PickSourceType: string implements SystemEnum
{
    use HasSystemEnum;

    case STOCK_REQUEST = 'stock_request';
    case STOCK_ISSUE = 'stock_issue';
    case TRANSFER_ORDER = 'transfer_order';
    case SALES_ORDER = 'sales_order';
}
