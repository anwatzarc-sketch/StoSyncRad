<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Average cost granularity (inventory.costing_level). */
enum CostingLevel: string implements SystemEnum
{
    use HasSystemEnum;

    case COMPANY = 'company';
    case WAREHOUSE = 'warehouse';
}
