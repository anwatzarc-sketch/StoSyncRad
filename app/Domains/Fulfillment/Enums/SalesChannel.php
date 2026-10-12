<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Sales channel (stock-side fulfillment only). */
enum SalesChannel: string implements SystemEnum
{
    use HasSystemEnum;

    case RETAIL_COUNTER = 'retail_counter';
    case ECOMMERCE = 'ecommerce';
    case WHOLESALE = 'wholesale';
}
