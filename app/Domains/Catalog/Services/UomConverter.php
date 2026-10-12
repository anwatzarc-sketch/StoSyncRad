<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Services;

use App\Domains\Catalog\Enums\TrackingMode;
use App\Domains\Catalog\Models\Item;
use App\Domains\Catalog\Models\UnitOfMeasure;
use App\Support\Enums\RoundingMode;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Math\Decimal as D;
use Illuminate\Support\Facades\DB;

/**
 * Deterministic unit conversion to an item's base unit (Chapter 06 §6.8, Chapter 05 §5.5).
 *   1. same unit → factor 1
 *   2. item-specific conversion (item_uom_conversions)
 *   3. same UoM category → ratio_to_reference(unit) / ratio_to_reference(base)
 * Cross-category conversion without an item conversion is rejected. Results are rounded
 * half-up to 6 decimals at this boundary; whole-number bases (serial items, whole units)
 * must convert to whole quantities.
 */
final class UomConverter
{
    /** @var array<string, object> */
    private array $units = [];

    /** @return array{0: string, 1: string} [baseQuantity, conversionFactor] */
    public function toBase(Item $item, ?string $uomId, string $quantity): array
    {
        $factor = $this->factor($item, $uomId);
        $base = D::mul($quantity, $factor, D::QTY_SCALE, RoundingMode::HALF_UP);

        $baseUnit = $this->unit($item->base_uom_id);
        if (($item->tracking_mode === TrackingMode::SERIAL || $baseUnit->is_whole_number) && ! D::isWhole($base)) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED,
                "{$item->label()} is counted in whole units; {$quantity} converts to {$base}.",
                ['base_quantity' => $base, 'conversion_factor' => $factor]);
        }

        return [$base, $factor];
    }

    public function factor(Item $item, ?string $uomId): string
    {
        if ($uomId === null || $uomId === $item->base_uom_id) {
            return '1.0000000000';
        }
        $itemFactor = DB::table('item_uom_conversions')->where('item_id', $item->id)->where('uom_id', $uomId)->where('is_active', true)->value('conversion_factor');
        if ($itemFactor !== null) {
            return D::exact($itemFactor, 10);
        }
        $from = $this->unit($uomId);
        $base = $this->unit($item->base_uom_id);
        if ($from->uom_category_id !== $base->uom_category_id) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED,
                "No conversion from {$from->code} to {$base->code} is defined for {$item->label()}.",
                ['uom_id' => $uomId, 'base_uom_id' => $item->base_uom_id]);
        }

        return D::div($from->ratio_to_reference, $base->ratio_to_reference, 10);
    }

    private function unit(string $id): object
    {
        if (! isset($this->units[$id])) {
            $unit = DB::table((new UnitOfMeasure)->getTable())->where('id', $id)->first();
            if ($unit === null) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Unknown unit of measure.', ['uom_id' => $id]);
            }
            $this->units[$id] = $unit;
        }

        return $this->units[$id];
    }
}
