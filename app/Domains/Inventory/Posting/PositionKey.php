<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Posting;

use App\Domains\Inventory\Enums\StockCondition;
use App\Support\Sentinel;

/**
 * Identity of a stock position (Chapter 07 §7.3). Absent dimensions use the sentinel UUID.
 * sortKey() defines the single global lock order used by every posting (Chapter 09 §9.6).
 */
final class PositionKey
{
    public readonly string $variantId;

    public readonly string $lotId;

    public readonly string $ownerId;

    public readonly string $packageId;

    public function __construct(
        public readonly string $itemId,
        ?string $variantId,
        public readonly string $locationId,
        ?string $lotId,
        public readonly StockCondition $condition,
        ?string $ownerId = null,
        ?string $packageId = null,
    ) {
        $this->variantId = Sentinel::orId($variantId);
        $this->lotId = Sentinel::orId($lotId);
        $this->ownerId = Sentinel::orId($ownerId);
        $this->packageId = Sentinel::orId($packageId);
    }

    public function sortKey(): string
    {
        return implode('|', [$this->itemId, $this->variantId, $this->locationId, $this->lotId, $this->condition->value, $this->ownerId, $this->packageId]);
    }

    public function equals(self $other): bool
    {
        return $this->sortKey() === $other->sortKey();
    }

    /** @return array<string, string> column map for inventory_balances */
    public function columns(): array
    {
        return [
            'item_id' => $this->itemId,
            'item_variant_id' => $this->variantId,
            'inventory_location_id' => $this->locationId,
            'inventory_lot_id' => $this->lotId,
            'stock_condition' => $this->condition->value,
            'owner_partner_id' => $this->ownerId,
            'package_id' => $this->packageId,
        ];
    }
}
