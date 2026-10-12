<?php

declare(strict_types=1);

namespace App\Domains\Planning\Schemas;

use App\Domains\Catalog\Models\Item;
use App\Domains\Catalog\Models\ItemVariant;
use App\Domains\Organization\Models\Warehouse;
use App\Domains\Planning\Enums\ForecastMethod;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Demand forecast input (overrides the default method per item/warehouse). */
final class ForecastInputSchema extends TableSchema
{
    public static function table(): string
    {
        return 'planning_forecast_inputs';
    }

    protected static function define(): array
    {
        return [
            'item_id' => Property::uuid()->requiredOnCreate()->references(Item::class)->filterable()->label('Item'),
            'item_variant_id' => Property::uuid()->nullable()->references(ItemVariant::class)->label('Variant'),
            'warehouse_id' => Property::uuid()->nullable()->references(Warehouse::class)->filterable()->label('Warehouse', 'Blank = company-wide.'),
            'method' => Property::enum(ForecastMethod::class)->default(ForecastMethod::MOVING_AVERAGE->value)->filterable()->label('Method'),
            'horizon_days' => Property::integer()->default(30)->min(1)->max(365)->label('Horizon (days)'),
            'window_days' => Property::integer()->default(90)->min(7)->max(730)->label('History window (days)'),
            'seasonality_months' => Property::integer()->nullable()->min(1)->max(24)->label('Seasonality (months)'),
            'manual_daily_demand' => Property::quantity()->nullable()->nonNegative()->label('Manual daily demand'),
            'valid_from' => Property::date()->nullable()->label('Valid from'),
            'valid_to' => Property::date()->nullable()->after('valid_from')->label('Valid to'),
            'reason' => Property::text(1000)->nullable()->label('Reason'),
        ];
    }
}
