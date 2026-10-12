<?php

declare(strict_types=1);

namespace App\Domains\Platform\Config\Keys;

use App\Domains\Planning\Enums\ForecastMethod;
use App\Domains\Platform\Config\DefinesConfigKeys;
use App\Domains\Platform\Config\ProvidesConfigKeys;

/** Demand forecasting and replenishment defaults (Chapter 08 §8.1). Per-item overrides live in forecast inputs. */
final class PlanningKeys implements ProvidesConfigKeys
{
    use DefinesConfigKeys;

    public static function definitions(): array
    {
        return [
            self::enum('planning.default_forecast_method', ForecastMethod::class, 'moving_average', 'Forecast method', 'Method used when an item has no forecast input.', 'GW'),
            self::int('planning.default_window_days', 90, 'Demand window (days)', 'History window used to estimate daily demand.', 'GW', 7, 730),
            self::int('planning.default_horizon_days', 30, 'Forecast horizon (days)', 'Days of demand covered by allocation suggestions.', 'GW', 1, 365),
            self::bool('planning.include_transfers_in_demand', true, 'Count transfers out as demand', 'Treat transfer dispatches out of a warehouse as its demand.', 'GW'),
        ];
    }
}
