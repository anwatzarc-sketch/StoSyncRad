<?php

declare(strict_types=1);

namespace App\Domains\Billing\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** What an invoice created from a sales order bills: the ordered quantity (advance billing) or what was delivered. */
enum InvoiceBasis: string implements SystemEnum
{
    use HasSystemEnum;

    case ORDERED = 'ordered';
    case DELIVERED = 'delivered';
}
