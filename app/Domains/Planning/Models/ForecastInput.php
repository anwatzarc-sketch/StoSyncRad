<?php

declare(strict_types=1);

namespace App\Domains\Planning\Models;

use App\Domains\Planning\Enums\ForecastMethod;
use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Forecast method/override per item and warehouse (Chapter 08 §8.1). */
final class ForecastInput extends BaseModel
{
    protected $table = 'planning_forecast_inputs';

    protected $fillable = ['item_id', 'item_variant_id', 'warehouse_id', 'method', 'horizon_days', 'window_days', 'seasonality_months', 'manual_daily_demand', 'valid_from', 'valid_to', 'reason', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return [
            'method' => ForecastMethod::class,
            'horizon_days' => 'integer',
            'window_days' => 'integer',
            'seasonality_months' => 'integer',
            'valid_from' => 'immutable_date',
            'valid_to' => 'immutable_date',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Catalog\Models\Item::class, 'item_id');
    }
}
