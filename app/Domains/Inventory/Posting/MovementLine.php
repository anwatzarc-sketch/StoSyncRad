<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Posting;

use App\Domains\Inventory\Enums\StockCondition;
use App\Support\Math\Decimal;
use InvalidArgumentException;

/**
 * One requested ledger line: a positive base-unit quantity moving between two inventory
 * locations (and/or conditions). Built by workflow actions; consumed by InventoryPoster.
 */
final class MovementLine
{
    public readonly string $quantity;

    /**
     * @param  string|null  $unitCost  required for stock entering from an external location
     *                                 (receipt/opening); ignored for outgoing lines unless
     *                                 $fixedCost (reversals use the original snapshot).
     * @param  array<string, string>  $consumeReservations  reservation id → base quantity consumed by this line
     */
    public function __construct(
        public readonly string $itemId,
        public readonly ?string $variantId,
        public readonly string $fromLocationId,
        public readonly StockCondition $fromCondition,
        public readonly string $toLocationId,
        public readonly StockCondition $toCondition,
        string $quantity,
        public readonly ?string $lotId = null,
        public readonly ?string $serialId = null,
        public readonly ?string $unitCost = null,
        public readonly bool $fixedCost = false,
        public readonly ?string $documentUomId = null,
        public readonly ?string $documentQuantity = null,
        public readonly string $conversionFactor = '1',
        public readonly ?string $sourceLineType = null,
        public readonly ?string $sourceLineId = null,
        public readonly bool $allowNegative = false,
        public readonly array $consumeReservations = [],
        public readonly ?string $fifoLayerSourceLineId = null,
        public readonly ?string $reversesLineId = null,
    ) {
        if (! Decimal::isPositive($quantity)) {
            throw new InvalidArgumentException('Movement quantities must be positive.');
        }
        $this->quantity = Decimal::exact($quantity);
    }

    public function from(): PositionKey
    {
        return new PositionKey($this->itemId, $this->variantId, $this->fromLocationId, $this->lotId, $this->fromCondition);
    }

    public function to(): PositionKey
    {
        return new PositionKey($this->itemId, $this->variantId, $this->toLocationId, $this->lotId, $this->toCondition);
    }
}
