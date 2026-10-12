<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Lot lock state; locked lots cannot move except by the locking workflow. */
enum LotLockStatus: string implements SystemEnum
{
    use HasSystemEnum;

    case UNLOCKED = 'unlocked';
    case LOCKED_IN_TRANSIT = 'locked_in_transit';
    case LOCKED_INVESTIGATION = 'locked_investigation';
    case LOCKED_RECALL = 'locked_recall';
}
