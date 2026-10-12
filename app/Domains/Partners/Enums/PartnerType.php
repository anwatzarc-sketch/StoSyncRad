<?php

declare(strict_types=1);

namespace App\Domains\Partners\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Partner role. */
enum PartnerType: string implements SystemEnum
{
    use HasSystemEnum;

    case SUPPLIER = 'supplier';
    case CUSTOMER = 'customer';
    case CARRIER = 'carrier';
    case INTERNAL_RECIPIENT = 'internal_recipient';
    case MANUFACTURER = 'manufacturer';
}
