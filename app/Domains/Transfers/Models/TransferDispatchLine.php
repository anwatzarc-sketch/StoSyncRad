<?php

declare(strict_types=1);

namespace App\Domains\Transfers\Models;

use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Dispatched lot/serial/quantity; receipts must match these exactly. */
final class TransferDispatchLine extends BaseModel
{
    protected $table = 'transfer_dispatch_lines';

    protected $fillable = ['transfer_dispatch_id', 'transfer_order_line_id', 'item_id', 'item_variant_id', 'inventory_lot_id', 'item_serial_id', 'source_storage_location_id', 'base_quantity', 'received_base_quantity', 'resolved_base_quantity', 'unit_cost_snapshot'];

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(TransferDispatch::class, 'transfer_dispatch_id');
    }

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(TransferOrderLine::class, 'transfer_order_line_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Catalog\Models\Item::class, 'item_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Inventory\Models\InventoryLot::class, 'inventory_lot_id');
    }

    public function serial(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Inventory\Models\ItemSerial::class, 'item_serial_id');
    }
}
