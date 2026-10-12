<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Inventory costing method (Chapter 07 §7.9). */
enum CostingMethod: string implements SystemEnum
{
    use HasSystemEnum;

    case MOVING_AVERAGE = 'moving_average';
    case FIFO = 'fifo';
    case STANDARD = 'standard';
    case INHERIT_CATEGORY = 'inherit_category';
}
