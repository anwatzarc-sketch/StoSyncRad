<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Models;

use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Stock request line. */
final class StockRequestLine extends BaseModel
{
    protected $table = 'stock_request_lines';

    protected $fillable = ['stock_request_id', 'line_no', 'item_id', 'item_variant_id', 'uom_id', 'conversion_factor_snapshot', 'requested_quantity', 'requested_base_quantity', 'approved_base_quantity', 'fulfilled_base_quantity', 'returned_base_quantity', 'notes'];

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
        ];
    }

    public function header(): BelongsTo
    {
        return $this->belongsTo(StockRequest::class, 'stock_request_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Catalog\Models\Item::class, 'item_id');
    }
}
