<?php

declare(strict_types=1);

namespace App\Domains\Transfers\Models;

use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Transfer order line with quantity projections. */
final class TransferOrderLine extends BaseModel
{
    protected $table = 'transfer_order_lines';

    protected $fillable = ['transfer_order_id', 'line_no', 'item_id', 'item_variant_id', 'inventory_lot_id', 'uom_id', 'conversion_factor_snapshot', 'requested_quantity', 'requested_base_quantity', 'approved_base_quantity', 'dispatched_base_quantity', 'received_base_quantity', 'resolved_base_quantity', 'cancelled_base_quantity', 'notes'];

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
        ];
    }

    public function header(): BelongsTo
    {
        return $this->belongsTo(TransferOrder::class, 'transfer_order_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Catalog\Models\Item::class, 'item_id');
    }
}
