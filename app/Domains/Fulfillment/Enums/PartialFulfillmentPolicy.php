<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Whether requests may be partially fulfilled. */
enum PartialFulfillmentPolicy: string implements SystemEnum
{
    use HasSystemEnum;

    case ALLOW = 'allow';
    case FORBID = 'forbid';
}
