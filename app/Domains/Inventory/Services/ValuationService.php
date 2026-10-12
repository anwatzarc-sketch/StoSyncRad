<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Services;

use App\Domains\Catalog\Enums\CostingMethod;
use App\Domains\Inventory\Enums\CostingLevel;
use App\Domains\Inventory\Enums\ValuationLayerStatus;
use App\Domains\Organization\Models\InventoryLocation;
use App\Domains\Platform\Config\PlatformConfig;
use App\Support\Enums\RoundingMode;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Math\Decimal as D;
use App\Support\Sentinel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Inventory valuation (Chapter 07 §7.9) over cost "buckets":
 *   - company-level costing: every owned location shares one bucket (sentinel)
 *   - warehouse-level costing: each warehouse is a bucket; in-transit stock sits in the sentinel bucket
 * External locations (supplier, customer, adjustment…) have no bucket.
 *
 *   external → owned   incoming at the supplied cost (FIFO: new layer; standard: at standard + variance)
 *   owned → external   outgoing at bucket cost (average / consumed FIFO layers / standard)
 *   owned → owned      neutral when the bucket is unchanged; otherwise out of the source bucket and
 *                      into the destination bucket at the outgoing cost (transfers never create profit)
 *
 * Usage inside a posting: lock() the projections first, then plan per line, then persist() after the
 * ledger lines exist (layers and consumptions reference them by foreign key).
 */
final class ValuationService
{
    /** @var array<string, object> projection rows keyed "item|variant|bucket" */
    private array $projections = [];

    /** @var array<int, array<string,mixed>> FIFO layers to insert */
    private array $newLayers = [];

    /** @var array<int, array<string,mixed>> FIFO consumptions to insert */
    private array $consumptions = [];

    /** @var array<string, string> layer id → new remaining quantity */
    private array $layerUpdates = [];

    /** @var array<int, array<string,mixed>> standard-cost variances */
    private array $variances = [];

    public function __construct(private readonly PlatformConfig $config) {}

    public function bucket(InventoryLocation $location): ?string
    {
        if (! $location->location_type->holdsOwnedStock()) {
            return null;
        }
        $level = CostingLevel::from((string) $this->config->get('inventory.costing_level'));

        return $level === CostingLevel::WAREHOUSE && $location->warehouse_id !== null ? $location->warehouse_id : Sentinel::UUID;
    }

    /**
     * Lock (creating if needed) every projection touched by this posting, in key order.
     *
     * @param  array<int, array{0: string, 1: ?string, 2: string}>  $keys  [itemId, variantId, bucket]
     */
    public function lock(array $keys): void
    {
        $this->projections = [];
        $this->newLayers = $this->consumptions = $this->layerUpdates = $this->variances = [];
        $unique = [];
        foreach ($keys as [$item, $variant, $bucket]) {
            $unique[$item.'|'.Sentinel::orId($variant).'|'.$bucket] = [$item, Sentinel::orId($variant), $bucket];
        }
        if ($unique === []) {
            return;
        }
        ksort($unique, SORT_STRING);
        $now = now();
        DB::table('item_cost_projections')->insertOrIgnore(array_map(static fn (array $k) => [
            'id' => (string) Str::uuid7(), 'item_id' => $k[0], 'item_variant_id' => $k[1], 'warehouse_id' => $k[2],
            'quantity' => '0', 'total_value' => '0', 'average_cost' => '0', 'created_at' => $now, 'updated_at' => $now,
        ], array_values($unique)));

        $tuples = implode(', ', array_fill(0, count($unique), '(?::uuid, ?::uuid, ?::uuid)'));
        $rows = DB::select(
            "SELECT * FROM item_cost_projections WHERE (item_id, item_variant_id, warehouse_id) IN ($tuples)
              ORDER BY item_id, item_variant_id, warehouse_id FOR UPDATE",
            array_merge(...array_values($unique))
        );
        foreach ($rows as $row) {
            $this->projections[$row->item_id.'|'.$row->item_variant_id.'|'.$row->warehouse_id] = $row;
        }
    }

    /**
     * Cost of stock leaving a bucket. Updates the in-memory projection.
     *
     * @param  string|null  $fixedCost  reversal/return at a known original cost
     */
    public function outgoing(CostingMethod $method, string $itemId, ?string $variantId, string $bucket, string $qty, string $lineId,
        ?string $fixedCost = null, ?string $standardCost = null, ?string $fifoSourceLineId = null): string
    {
        $p = $this->projection($itemId, $variantId, $bucket);

        $unitCost = match (true) {
            $fixedCost !== null && $method !== CostingMethod::FIFO => D::exact($fixedCost, D::COST_SCALE),
            $method === CostingMethod::STANDARD => D::exact($standardCost ?? $p->average_cost, D::COST_SCALE),
            $method === CostingMethod::FIFO => $this->consumeFifo($itemId, $variantId, $bucket, $qty, $lineId, $fifoSourceLineId, $fixedCost),
            default => D::exact($p->average_cost, D::COST_SCALE),
        };

        $value = D::mul($qty, $unitCost, D::MONEY_SCALE);
        $newQty = D::sub($p->quantity, $qty);
        $newValue = D::sub($p->total_value, $value, D::MONEY_SCALE);
        if (! D::isPositive($newQty)) {
            // Empty (or negative under override): value follows quantity at the last known cost.
            $newValue = D::mul($newQty, $p->average_cost, D::MONEY_SCALE);
        }
        $p->quantity = $newQty;
        $p->total_value = $newValue;
        if (D::isPositive($newQty)) {
            $p->average_cost = D::div($newValue, $newQty, D::COST_SCALE);
        }

        return $unitCost;
    }

    /** Stock entering a bucket at a unit cost. Updates the in-memory projection. */
    public function incoming(CostingMethod $method, string $itemId, ?string $variantId, string $bucket, string $qty, string $unitCost, string $lineId,
        ?string $standardCost = null, ?\DateTimeInterface $layerDate = null): string
    {
        $p = $this->projection($itemId, $variantId, $bucket);
        // Posting boundary: unit costs are stored at 8 decimals (Chapter 05 §5.5).
        $unitCost = D::round($unitCost, D::COST_SCALE);
        $bookedCost = $unitCost;

        if ($method === CostingMethod::STANDARD) {
            $bookedCost = D::exact($standardCost ?? $unitCost, D::COST_SCALE);
            if (! D::eq($bookedCost, $unitCost)) {
                $this->variances[] = [
                    'id' => (string) Str::uuid7(), 'item_id' => $itemId, 'stock_movement_line_id' => $lineId,
                    'standard_unit_cost' => $bookedCost, 'actual_unit_cost' => D::exact($unitCost, D::COST_SCALE), 'quantity' => $qty,
                    'variance_value' => D::mul($qty, D::sub($unitCost, $bookedCost, D::COST_SCALE), D::MONEY_SCALE), 'created_at' => now(),
                ];
            }
        }
        if ($method === CostingMethod::FIFO) {
            $this->newLayers[] = [
                'id' => (string) Str::uuid7(), 'item_id' => $itemId, 'item_variant_id' => Sentinel::orId($variantId), 'warehouse_id' => $bucket,
                'stock_movement_line_id' => $lineId, 'original_quantity' => $qty, 'remaining_quantity' => $qty, 'unit_cost' => $bookedCost,
                'status' => ValuationLayerStatus::OPEN->value, 'layer_date' => $layerDate ?? now(), 'created_at' => now(), 'updated_at' => now(),
            ];
        }

        $value = D::mul($qty, $bookedCost, D::MONEY_SCALE);
        $wasNegative = D::isNegative($p->quantity);
        $p->quantity = D::add($p->quantity, $qty);
        $p->total_value = $wasNegative && ! D::isNegative($p->quantity)
            ? D::mul($p->quantity, $bookedCost, D::MONEY_SCALE)
            : D::add($p->total_value, $value, D::MONEY_SCALE);
        if (D::isPositive($p->quantity)) {
            $p->average_cost = D::div($p->total_value, $p->quantity, D::COST_SCALE);
        }

        return D::exact($bookedCost, D::COST_SCALE);
    }

    /** Current average cost for informational snapshots on neutral moves. */
    public function currentCost(string $itemId, ?string $variantId, string $bucket): string
    {
        return D::exact($this->projection($itemId, $variantId, $bucket)->average_cost, D::COST_SCALE);
    }

    /**
     * Read-only cost estimate outside a posting (snapshots, approval values, previews): the bucket's
     * average cost, else the item's company-wide average, else its standard cost.
     */
    public function estimateCost(string $itemId, ?string $variantId, ?string $bucket): string
    {
        $cost = $bucket === null ? null : DB::table('item_cost_projections')->where('item_id', $itemId)
            ->where('item_variant_id', Sentinel::orId($variantId))->where('warehouse_id', $bucket)->where('quantity', '>', 0)->value('average_cost');
        $cost ??= DB::table('items')->where('id', $itemId)->selectRaw('COALESCE(NULLIF(current_average_cost, 0), standard_cost, 0) AS c')->value('c');

        return D::exact((string) ($cost ?? '0'), D::COST_SCALE);
    }

    /** Write projections, layers, consumptions and variances (after the ledger lines exist). */
    public function persist(): void
    {
        $now = now();
        foreach ($this->projections as $p) {
            DB::update('UPDATE item_cost_projections SET quantity = ?, total_value = ?, average_cost = ?, version = version + 1, updated_at = ? WHERE id = ?',
                [$p->quantity, $p->total_value, $p->average_cost, $now, $p->id]);
            if (Sentinel::is($p->item_variant_id) && Sentinel::is($p->warehouse_id)) {
                DB::update('UPDATE items SET current_average_cost = ? WHERE id = ? AND current_average_cost IS DISTINCT FROM ?',
                    [$p->average_cost, $p->item_id, $p->average_cost]);
            }
        }
        foreach ($this->layerUpdates as $layerId => $remaining) {
            DB::update('UPDATE valuation_layers SET remaining_quantity = ?, status = ?, updated_at = ? WHERE id = ?',
                [$remaining, D::isZero($remaining) ? ValuationLayerStatus::CONSUMED->value : ValuationLayerStatus::OPEN->value, $now, $layerId]);
        }
        if ($this->newLayers !== []) {
            DB::table('valuation_layers')->insert($this->newLayers);
        }
        if ($this->consumptions !== []) {
            DB::table('valuation_layer_consumptions')->insert($this->consumptions);
        }
        if ($this->variances !== []) {
            DB::table('valuation_variances')->insert($this->variances);
        }
    }

    private function projection(string $itemId, ?string $variantId, string $bucket): object
    {
        $key = $itemId.'|'.Sentinel::orId($variantId).'|'.$bucket;
        if (! isset($this->projections[$key])) {
            throw new \LogicException("Cost projection $key was not locked before valuation.");
        }

        return $this->projections[$key];
    }

    private function consumeFifo(string $itemId, ?string $variantId, string $bucket, string $qty, string $lineId, ?string $sourceLineId, ?string $fixedCost): string
    {
        $query = 'SELECT id, remaining_quantity, unit_cost FROM valuation_layers
                   WHERE item_id = ? AND item_variant_id = ? AND warehouse_id = ? AND status = ?';
        $bindings = [$itemId, Sentinel::orId($variantId), $bucket, ValuationLayerStatus::OPEN->value];
        if ($sourceLineId !== null) {
            $query .= ' AND stock_movement_line_id = ?';
            $bindings[] = $sourceLineId;
        }
        $layers = DB::select($query.' ORDER BY layer_date, id FOR UPDATE', $bindings);

        $remaining = $qty;
        $value = '0';
        foreach ($layers as $layer) {
            if (! D::isPositive($remaining)) {
                break;
            }
            $available = $this->layerUpdates[$layer->id] ?? $layer->remaining_quantity;
            if (! D::isPositive($available)) {
                continue;
            }
            $take = D::min($available, $remaining);
            $this->layerUpdates[$layer->id] = D::sub($available, $take);
            $this->consumptions[] = [
                'id' => (string) Str::uuid7(), 'valuation_layer_id' => $layer->id, 'stock_movement_line_id' => $lineId,
                'quantity' => $take, 'unit_cost' => $layer->unit_cost, 'created_at' => now(),
            ];
            $value = D::add($value, D::mul($take, $layer->unit_cost, D::MONEY_SCALE), D::MONEY_SCALE);
            $remaining = D::sub($remaining, $take);
        }

        if (D::isPositive($remaining)) {
            if ($sourceLineId !== null) {
                throw DomainException::of(ErrorCode::REVERSAL_BLOCKED_DOWNSTREAM,
                    'The original receipt layer has already been consumed; reverse the dependent movements first.',
                    ['item_id' => $itemId, 'missing_quantity' => $remaining]);
            }
            // Negative stock under override: value the shortfall at the latest known cost.
            $fallback = $fixedCost ?? $this->projection($itemId, $variantId, $bucket)->average_cost;
            $value = D::add($value, D::mul($remaining, $fallback, D::MONEY_SCALE), D::MONEY_SCALE);
        }

        return D::div($value, $qty, D::COST_SCALE, RoundingMode::HALF_EVEN);
    }
}
