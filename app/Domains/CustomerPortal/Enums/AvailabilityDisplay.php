<?php

declare(strict_types=1);

namespace App\Domains\CustomerPortal\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** How stock availability is shown to customers (never locations). */
enum AvailabilityDisplay: string implements SystemEnum
{
    use HasSystemEnum;

    case BAND = 'band';
    case QUANTITY = 'quantity';
    case HIDDEN = 'hidden';
}
