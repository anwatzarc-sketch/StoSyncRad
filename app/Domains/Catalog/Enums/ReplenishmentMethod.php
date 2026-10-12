<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** How a reorder rule replenishes. */
enum ReplenishmentMethod: string implements SystemEnum
{
    use HasSystemEnum;

    case PURCHASE = 'purchase';
    case TRANSFER = 'transfer';
    case MANUAL = 'manual';
}
