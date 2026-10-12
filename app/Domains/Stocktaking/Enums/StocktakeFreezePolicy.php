<?php

declare(strict_types=1);

namespace App\Domains\Stocktaking\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** How movements during counting are handled. */
enum StocktakeFreezePolicy: string implements SystemEnum
{
    use HasSystemEnum;

    case FREEZE_POSITIONS = 'freeze_positions';
    case SNAPSHOT_AND_RECONCILE = 'snapshot_and_reconcile';
}
