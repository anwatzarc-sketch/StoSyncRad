<?php

declare(strict_types=1);

namespace App\Domains\Stocktaking\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Count line status. */
enum CountLineStatus: string implements SystemEnum
{
    use HasSystemEnum;

    case PENDING = 'pending';
    case COUNTED = 'counted';
    case RECOUNT_REQUIRED = 'recount_required';
    case RECOUNTED = 'recounted';
    case VERIFIED = 'verified';
    case EXCLUDED = 'excluded';
}
