<?php

declare(strict_types=1);

namespace App\Domains\Stocktaking\Models;

use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Signed adjustment line (+ found / − loss). */
final class StockAdjustmentLine extends BaseModel
{
    protected $table = 'stock_adjustment_lines';

    protected $fillable = ['stock_adjustment_id', 'line_no', 'item_id', 'item_variant_id', 'storage_location_id', 'inventory_lot_id', 'item_serial_id', 'lot_number', 'expiry_date', 'serial_number', 'stock_condition', 'quantity', 'unit_cost', 'value', 'stocktake_line_id', 'reason'];

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'expiry_date' => 'immutable_date',
            'stock_condition' => \App\Domains\Inventory\Enums\StockCondition::class,
        ];
    }

    public function header(): BelongsTo
    {
        return $this->belongsTo(StockAdjustment::class, 'stock_adjustment_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Catalog\Models\Item::class, 'item_id');
    }
}
