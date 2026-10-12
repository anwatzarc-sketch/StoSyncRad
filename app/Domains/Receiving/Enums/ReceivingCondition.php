<?php

declare(strict_types=1);

namespace App\Domains\Receiving\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Condition recorded per received line. */
enum ReceivingCondition: string implements SystemEnum
{
    use HasSystemEnum;

    case GOOD = 'good';
    case DAMAGED = 'damaged';
    case QUARANTINE = 'quarantine';
    case REJECTED = 'rejected';
}
