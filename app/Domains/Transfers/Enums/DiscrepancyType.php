<?php

declare(strict_types=1);

namespace App\Domains\Transfers\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Transfer discrepancy type. */
enum DiscrepancyType: string implements SystemEnum
{
    use HasSystemEnum;

    case SHORTAGE = 'shortage';
    case EXCESS = 'excess';
    case DAMAGE = 'damage';
    case LOT_MISMATCH = 'lot_mismatch';
    case SERIAL_MISMATCH = 'serial_mismatch';
}
