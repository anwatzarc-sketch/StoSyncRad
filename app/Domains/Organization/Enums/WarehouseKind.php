<?php

declare(strict_types=1);

namespace App\Domains\Organization\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Warehouse kind (Chapter 06 §6.3). */
enum WarehouseKind: string implements SystemEnum
{
    use HasSystemEnum;

    case MAIN = 'main';
    case SATELLITE = 'satellite';
    case COLD_STORAGE = 'cold_storage';
    case BONDED = 'bonded';
    case QUARANTINE = 'quarantine';
    case RETURNS = 'returns';
    case VIRTUAL_TRANSIT = 'virtual_transit';
}
