<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Models;

use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Purchase order line. */
final class PurchaseOrderLine extends BaseModel
{
    protected $table = 'purchase_order_lines';

    protected $fillable = ['purchase_order_id', 'line_no', 'purchase_request_line_id', 'item_id', 'item_variant_id', 'quantity', 'uom_id', 'conversion_factor_snapshot', 'base_quantity', 'unit_price', 'discount_percent', 'tax_percent', 'line_total', 'received_base_quantity', 'rejected_base_quantity', 'returned_base_quantity', 'cancelled_base_quantity', 'expected_date', 'notes'];

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'expected_date' => 'immutable_date',
        ];
    }

    public function header(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Catalog\Models\Item::class, 'item_id');
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Catalog\Models\UnitOfMeasure::class, 'uom_id');
    }
}
