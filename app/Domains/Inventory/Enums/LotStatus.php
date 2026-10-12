<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Lot lifecycle. */
enum LotStatus: string implements SystemEnum
{
    use HasSystemEnum;

    case ACTIVE = 'active';
    case DEPLETED = 'depleted';
    case EXPIRED = 'expired';
    case RECALLED = 'recalled';
    case ARCHIVED = 'archived';
}
