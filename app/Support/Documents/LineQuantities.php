<?php

declare(strict_types=1);

namespace App\Support\Documents;

use App\Domains\Catalog\Enums\ItemStatus;
use App\Domains\Catalog\Models\Item;
use App\Domains\Catalog\Services\UomConverter;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;

/**
 * Resolves document line items and converts entered quantities to base units with the
 * conversion factor snapshotted on the line (Chapter 06 §6.4, Chapter 05 §5.5).
 */
final class LineQuantities
{
    /** @var array<string, Item> */
    private array $items = [];

    public function __construct(private readonly UomConverter $converter) {}

    public function item(string $id, bool $requireActive = true): Item
    {
        $item = $this->items[$id] ??= Item::query()->findOrFail($id);
        if ($requireActive && $item->status !== ItemStatus::ACTIVE) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, "{$item->label()} is not active.", ['item_id' => $id]);
        }

        return $item;
    }

    /**
     * @return array{0: string, 1: string, 2: string} [uomId, baseQuantity, conversionFactor]
     */
    public function toBase(string $itemId, ?string $uomId, string $quantity): array
    {
        $item = $this->item($itemId, false);
        $uomId ??= $item->base_uom_id;
        [$base, $factor] = $this->converter->toBase($item, $uomId, $quantity);

        return [$uomId, $base, $factor];
    }
}
