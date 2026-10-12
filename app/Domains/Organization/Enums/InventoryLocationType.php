<?php

declare(strict_types=1);

namespace App\Domains\Organization\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Physical or controlled virtual inventory location (Chapter 06 §6.5). */
enum InventoryLocationType: string implements SystemEnum
{
    use HasSystemEnum;

    case PHYSICAL = 'physical';
    case IN_TRANSIT = 'in_transit';
    case SUPPLIER = 'supplier';
    case CUSTOMER = 'customer';
    case ADJUSTMENT = 'adjustment';
    case OPENING_BALANCE = 'opening_balance';
    case SCRAP = 'scrap';
    case PRODUCTION = 'production';
    case RETURN_INSPECTION = 'return_inspection';
    case INVENTORY_LOSS = 'inventory_loss';
    case CROSS_DOCK_VIRTUAL = 'cross_dock_virtual';

    public function isVirtual(): bool
    {
        return $this !== self::PHYSICAL;
    }

    /** Virtual locations that hold company-owned stock (counted in valuation). */
    public function holdsOwnedStock(): bool
    {
        return in_array($this, [self::PHYSICAL, self::IN_TRANSIT, self::RETURN_INSPECTION, self::CROSS_DOCK_VIRTUAL], true);
    }
}
