<?php

declare(strict_types=1);

namespace App\Domains\Stocktaking\Models;

use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Count line. */
final class StocktakeLine extends BaseModel
{
    protected $table = 'stocktake_lines';

    protected $fillable = ['stocktake_id', 'line_no', 'inventory_balance_id', 'item_id', 'item_variant_id', 'storage_location_id', 'inventory_lot_id', 'stock_condition', 'snapshot_quantity', 'movement_since_snapshot', 'counted_quantity', 'recount_quantity', 'final_quantity', 'variance_quantity', 'variance_value', 'unit_cost_snapshot', 'count_status', 'counted_by', 'counted_at', 'recounted_by', 'recounted_at', 'is_unexpected', 'notes'];

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'stock_condition' => \App\Domains\Inventory\Enums\StockCondition::class,
            'count_status' => \App\Domains\Stocktaking\Enums\CountLineStatus::class,
            'counted_at' => 'immutable_datetime',
            'recounted_at' => 'immutable_datetime',
            'is_unexpected' => 'boolean',
        ];
    }

    public function header(): BelongsTo
    {
        return $this->belongsTo(Stocktake::class, 'stocktake_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Catalog\Models\Item::class, 'item_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Organization\Models\StorageLocation::class, 'storage_location_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Inventory\Models\InventoryLot::class, 'inventory_lot_id');
    }
}
