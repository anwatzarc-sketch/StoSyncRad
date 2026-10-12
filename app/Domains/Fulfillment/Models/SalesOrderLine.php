<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Models;

use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Sales order line. */
final class SalesOrderLine extends BaseModel
{
    protected $table = 'sales_order_lines';

    protected $fillable = ['sales_order_id', 'line_no', 'item_id', 'item_variant_id', 'uom_id', 'conversion_factor_snapshot', 'quantity', 'base_quantity', 'delivered_base_quantity', 'returned_base_quantity', 'unit_price', 'line_total', 'notes'];

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
        ];
    }

    public function header(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Catalog\Models\Item::class, 'item_id');
    }
}
