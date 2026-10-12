<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Ledger movement status. */
enum MovementStatus: string implements SystemEnum
{
    use HasSystemEnum;

    case POSTED = 'posted';
    case REVERSED = 'reversed';
    case PARTIALLY_REVERSED = 'partially_reversed';
}
