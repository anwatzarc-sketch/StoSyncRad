<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Models;

use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Supplier return line. */
final class SupplierReturnLine extends BaseModel
{
    protected $table = 'supplier_return_lines';

    protected $fillable = ['supplier_return_id', 'line_no', 'goods_receipt_line_id', 'purchase_order_line_id', 'item_id', 'item_variant_id', 'inventory_lot_id', 'item_serial_id', 'storage_location_id', 'stock_condition', 'quantity', 'uom_id', 'conversion_factor_snapshot', 'base_quantity', 'unit_cost', 'notes'];

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'stock_condition' => \App\Domains\Inventory\Enums\StockCondition::class,
        ];
    }

    public function header(): BelongsTo
    {
        return $this->belongsTo(SupplierReturn::class, 'supplier_return_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Catalog\Models\Item::class, 'item_id');
    }
}
