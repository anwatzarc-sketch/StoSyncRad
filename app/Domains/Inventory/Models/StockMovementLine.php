<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Models;

use App\Domains\Inventory\Enums\StockCondition;
use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only ledger line: a positive quantity moved between two inventory locations. */
final class StockMovementLine extends BaseModel
{
    protected $table = 'stock_movement_lines';

    protected $fillable = ['stock_movement_id', 'line_no', 'item_id', 'item_variant_id', 'source_inventory_location_id', 'destination_inventory_location_id', 'source_condition', 'destination_condition', 'inventory_lot_id', 'item_serial_id', 'quantity', 'base_uom_id', 'document_uom_id', 'document_quantity', 'conversion_factor_snapshot', 'unit_cost_snapshot', 'total_cost_snapshot', 'currency', 'source_line_type', 'source_line_id', 'source_warehouse_id', 'destination_warehouse_id', 'reversed_by_line_id', 'reversed_quantity', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'source_condition' => StockCondition::class,
            'destination_condition' => StockCondition::class,
            'line_no' => 'integer',
        ];
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Catalog\Models\Item::class, 'item_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'inventory_lot_id');
    }

    public function serial(): BelongsTo
    {
        return $this->belongsTo(ItemSerial::class, 'item_serial_id');
    }

    public function sourceLocation(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Organization\Models\InventoryLocation::class, 'source_inventory_location_id');
    }

    public function destinationLocation(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Organization\Models\InventoryLocation::class, 'destination_inventory_location_id');
    }
}
