<?php

declare(strict_types=1);

namespace App\Domains\Transfers\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Batch verification strictness at transfer receipt. */
enum BatchVerifyStrictness: string implements SystemEnum
{
    use HasSystemEnum;

    case LOT_AND_SERIAL = 'lot_and_serial';
    case LOT_ONLY = 'lot_only';
}
