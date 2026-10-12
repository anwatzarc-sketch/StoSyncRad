<?php

declare(strict_types=1);

namespace App\Domains\Billing\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** A verified payment recorded by finance; voiding keeps the history. */
enum PaymentStatus: string implements SystemEnum
{
    use HasSystemEnum;

    case POSTED = 'posted';
    case VOID = 'void';
}
