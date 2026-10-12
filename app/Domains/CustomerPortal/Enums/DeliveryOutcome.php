<?php

declare(strict_types=1);

namespace App\Domains\CustomerPortal\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Customer's acknowledgement of a delivery. */
enum DeliveryOutcome: string implements SystemEnum
{
    use HasSystemEnum;

    case RECEIVED_IN_FULL = 'received_in_full';
    case RECEIVED_WITH_ISSUES = 'received_with_issues';
    case NOT_RECEIVED = 'not_received';
}
