<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Pick line status. */
enum PickLineStatus: string implements SystemEnum
{
    use HasSystemEnum;

    case PENDING = 'pending';
    case PICKED = 'picked';
    case SHORT_PICKED = 'short_picked';
    case SKIPPED = 'skipped';
}
