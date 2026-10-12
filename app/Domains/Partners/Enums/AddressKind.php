<?php

declare(strict_types=1);

namespace App\Domains\Partners\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Address kind. */
enum AddressKind: string implements SystemEnum
{
    use HasSystemEnum;

    case REGISTERED = 'registered';
    case OPERATIONAL = 'operational';
    case BILLING = 'billing';
    case SHIPPING = 'shipping';
    case OTHER = 'other';
}
