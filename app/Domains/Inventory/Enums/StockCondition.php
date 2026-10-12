<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Condition dimension of a stock position (Chapter 07 §7.2). */
enum StockCondition: string implements SystemEnum
{
    use HasSystemEnum;

    case AVAILABLE = 'available';
    case QUARANTINE = 'quarantine';
    case DAMAGED = 'damaged';
    case EXPIRED = 'expired';
    case BLOCKED = 'blocked';
    case RETURNED_PENDING_INSPECTION = 'returned_pending_inspection';

    /** Only `available` stock is issuable through ordinary workflows. */
    public function isIssuable(): bool
    {
        return $this === self::AVAILABLE;
    }
}
