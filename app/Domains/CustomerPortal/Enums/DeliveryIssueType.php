<?php

declare(strict_types=1);

namespace App\Domains\CustomerPortal\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Discrepancy reported on a delivery. */
enum DeliveryIssueType: string implements SystemEnum
{
    use HasSystemEnum;

    case SHORTAGE = 'shortage';
    case DAMAGED = 'damaged';
    case WRONG_ITEM = 'wrong_item';
    case OTHER = 'other';
}
