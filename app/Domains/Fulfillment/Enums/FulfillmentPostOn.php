<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** When a sales delivery posts stock out. */
enum FulfillmentPostOn: string implements SystemEnum
{
    use HasSystemEnum;

    case SHIP = 'ship';
    case DELIVERY_CONFIRMATION = 'delivery_confirmation';
}
