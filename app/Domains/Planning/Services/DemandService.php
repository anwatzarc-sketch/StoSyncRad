<?php

declare(strict_types=1);

namespace App\Domains\Planning\Services;

use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Planning\Enums\ForecastMethod;
use App\Domains\Planning\Models\ForecastInput;
use App\Domains\Platform\Config\PlatformConfig;
use App\Support\Math\Decimal as D;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Demand history derived on demand from the ledger (Chapter 08 §8.1) — there are no stored
 * forecast tables — and a daily-demand estimate per item/warehouse from the forecast input.
 */
final class DemandService
{
    public function __construct(private readonly PlatformConfig $config) {}

    /** @return array<int, string> movement types that count as demand leaving a warehouse */
    private function demandTypes(?string $warehouseId): array
    {
        $types = [MovementType::ISSUE->value, MovementType::SALE_DELIVERY->value, MovementType::PRODUCTION_ISSUE->value];
        if ($this->config->bool('planning.include_transfers_in_demand', ['warehouse' => $warehouseId])) {
            $types[] = MovementType::TRANSFER_DISPATCH->value;
        }

        return $types;
    }

    /**
     * Demand per period: gross outbound and customer/internal returns, net = gross − returns.
     *
     * @return array<int, array{period: string, gross: string, returns: string, net: string}>
     */
    public function history(string $itemId, ?string $variantId, ?string $warehouseId, string $from, string $to, string $granularity = 'month'): array
    {
        // First day of the period as YYYY-MM-DD (weeks start on Monday, as ISO weeks do).
        $period = match ($granularity) {
            'day' => "DATE_FORMAT(m.business_date, '%Y-%m-%d')",
            'week' => "DATE_FORMAT(m.business_date - INTERVAL WEEKDAY(m.business_date) DAY, '%Y-%m-%d')",
            'quarter' => "CONCAT(YEAR(m.business_date), '-', LPAD((QUARTER(m.business_date) - 1) * 3 + 1, 2, '0'), '-01')",
            'year' => "DATE_FORMAT(m.business_date, '%Y-01-01')",
            default => "DATE_FORMAT(m.business_date, '%Y-%m-01')",
        };
        $base = fn () => DB::table('stock_movement_lines as ml')->join('stock_movements as m', 'm.id', '=', 'ml.stock_movement_id')
            ->where('ml.item_id', $itemId)
            ->when($variantId !== null, fn ($q) => $q->where('ml.item_variant_id', $variantId))
            ->whereBetween('m.business_date', [$from, $to]);
        $gross = $base()->whereIn('m.movement_type', $this->demandTypes($warehouseId))
            ->when($warehouseId !== null, fn ($q) => $q->where('ml.source_warehouse_id', $warehouseId))
            ->groupByRaw($period)->selectRaw("$period AS period, SUM(ml.quantity) AS q")
            ->pluck('q', 'period');
        $returns = $base()->whereIn('m.movement_type', [MovementType::CUSTOMER_RETURN->value, MovementType::INTERNAL_RETURN->value])
            ->when($warehouseId !== null, fn ($q) => $q->where('ml.destination_warehouse_id', $warehouseId))
            ->groupByRaw($period)->selectRaw("$period AS period, SUM(ml.quantity) AS q")
            ->pluck('q', 'period');
        $periods = $gross->keys()->merge($returns->keys())->unique()->sort()->values();

        return $periods->map(fn ($p) => [
            'period' => $p, 'gross' => D::exact((string) ($gross[$p] ?? '0')), 'returns' => D::exact((string) ($returns[$p] ?? '0')),
            'net' => D::sub((string) ($gross[$p] ?? '0'), (string) ($returns[$p] ?? '0')),
        ])->all();
    }

    /** Forecast input in force for the item at a warehouse (warehouse-specific beats company-wide). */
    public function input(string $itemId, ?string $variantId, ?string $warehouseId): ?ForecastInput
    {
        $today = now()->toDateString();

        return ForecastInput::query()->where('item_id', $itemId)
            ->where(fn ($q) => $q->whereNull('item_variant_id')->when($variantId !== null, fn ($w) => $w->orWhere('item_variant_id', $variantId)))
            ->where(fn ($q) => $q->whereNull('warehouse_id')->when($warehouseId !== null, fn ($w) => $w->orWhere('warehouse_id', $warehouseId)))
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $today))
            ->orderByRaw('warehouse_id IS NULL, item_variant_id IS NULL')->first();
    }

    /**
     * Estimated daily demand (base units) with the method that produced it.
     *
     * @return array{daily: string, method: string, window_days: int, horizon_days: int}
     */
    public function dailyDemand(string $itemId, ?string $variantId, ?string $warehouseId): array
    {
        $scope = ['warehouse' => $warehouseId];
        $input = $this->input($itemId, $variantId, $warehouseId);
        $method = $input?->method ?? ForecastMethod::from((string) $this->config->get('planning.default_forecast_method', $scope));
        $window = (int) ($input?->window_days ?? $this->config->int('planning.default_window_days', $scope));
        $horizon = (int) ($input?->horizon_days ?? $this->config->int('planning.default_horizon_days', $scope));
        $today = CarbonImmutable::today();

        $daily = match ($method) {
            ForecastMethod::MANUAL => D::exact((string) ($input?->manual_daily_demand ?? '0')),
            ForecastMethod::MOVING_AVERAGE => D::div($this->netBetween($itemId, $variantId, $warehouseId, $today->subDays($window), $today), (string) $window, D::QTY_SCALE),
            ForecastMethod::WEIGHTED_MOVING_AVERAGE => $this->weighted($itemId, $variantId, $warehouseId, $today, $window),
            ForecastMethod::SEASONAL_NAIVE => (function () use ($itemId, $variantId, $warehouseId, $today, $horizon, $input) {
                $months = (int) ($input?->seasonality_months ?? 12);
                $start = $today->subMonthsNoOverflow($months);

                return D::div($this->netBetween($itemId, $variantId, $warehouseId, $start, $start->addDays($horizon)), (string) $horizon, D::QTY_SCALE);
            })(),
        };

        return ['daily' => D::isNegative($daily) ? '0.000000' : $daily, 'method' => $method->value, 'window_days' => $window, 'horizon_days' => $horizon];
    }

    /** Linearly weighted: the most recent quarter of the window counts 4×, the oldest 1×. */
    private function weighted(string $itemId, ?string $variantId, ?string $warehouseId, CarbonImmutable $today, int $window): string
    {
        $slice = max(1, intdiv($window, 4));
        $sum = '0';
        $weights = 0;
        for ($i = 0; $i < 4; $i++) {
            $end = $today->subDays($i * $slice);
            $qty = $this->netBetween($itemId, $variantId, $warehouseId, $end->subDays($slice), $end);
            $w = 4 - $i;
            $sum = D::add($sum, D::mul($qty, (string) $w, D::QTY_SCALE));
            $weights += $w * $slice;
        }

        return D::div($sum, (string) $weights, D::QTY_SCALE);
    }

    private function netBetween(string $itemId, ?string $variantId, ?string $warehouseId, CarbonImmutable $from, CarbonImmutable $to): string
    {
        $net = '0';
        foreach ($this->history($itemId, $variantId, $warehouseId, $from->toDateString(), $to->toDateString(), 'year') as $row) {
            $net = D::add($net, $row['net']);
        }

        return $net;
    }
}
