<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Models;

use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Purchase request line. */
final class PurchaseRequestLine extends BaseModel
{
    protected $table = 'purchase_request_lines';

    protected $fillable = ['purchase_request_id', 'line_no', 'item_id', 'item_variant_id', 'quantity', 'uom_id', 'conversion_factor_snapshot', 'base_quantity', 'ordered_base_quantity', 'estimated_unit_cost', 'suggested_supplier_id', 'notes'];

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
        ];
    }

    public function header(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'purchase_request_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Catalog\Models\Item::class, 'item_id');
    }
}
