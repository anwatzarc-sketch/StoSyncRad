<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Negative stock policy (stock.negative_stock_policy). */
enum NegativeStockPolicy: string implements SystemEnum
{
    use HasSystemEnum;

    case FORBID = 'forbid';
    case ALLOW_WITH_PERMISSION = 'allow_with_permission';
}
