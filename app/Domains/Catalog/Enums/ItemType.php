<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Item type (Appendix D). */
enum ItemType: string implements SystemEnum
{
    use HasSystemEnum;

    case STOCKABLE = 'stockable';
    case CONSUMABLE = 'consumable';
    case SERVICE = 'service';
    case KIT = 'kit';

    /** Whether quantities are tracked in the ledger. */
    public function isStockTracked(): bool
    {
        return $this === self::STOCKABLE || $this === self::CONSUMABLE;
    }
}
