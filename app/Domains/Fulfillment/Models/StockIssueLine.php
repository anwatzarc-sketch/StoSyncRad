<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Models;

use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Stock issue line. */
final class StockIssueLine extends BaseModel
{
    protected $table = 'stock_issue_lines';

    protected $fillable = ['stock_issue_id', 'line_no', 'stock_request_line_id', 'item_id', 'item_variant_id', 'storage_location_id', 'inventory_lot_id', 'item_serial_id', 'uom_id', 'conversion_factor_snapshot', 'quantity', 'base_quantity', 'returned_base_quantity', 'unit_cost_snapshot', 'short_pick_reason', 'notes'];

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
        ];
    }

    public function header(): BelongsTo
    {
        return $this->belongsTo(StockIssue::class, 'stock_issue_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Catalog\Models\Item::class, 'item_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Inventory\Models\InventoryLot::class, 'inventory_lot_id');
    }
}
