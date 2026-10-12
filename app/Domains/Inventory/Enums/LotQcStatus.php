<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Lot QC status. */
enum LotQcStatus: string implements SystemEnum
{
    use HasSystemEnum;

    case PENDING = 'pending';
    case RELEASED = 'released';
    case REJECTED = 'rejected';
    case CONDITIONAL = 'conditional';
}
