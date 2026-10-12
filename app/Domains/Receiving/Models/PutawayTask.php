<?php

declare(strict_types=1);

namespace App\Domains\Receiving\Models;

use App\Domains\Receiving\Enums\PutawayTaskStatus;
use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Put-away task from a receiving area to a storage bin. */
final class PutawayTask extends BaseModel
{
    protected $table = 'putaway_tasks';

    protected $fillable = ['document_number', 'warehouse_id', 'goods_receipt_id', 'transfer_receipt_id', 'item_id', 'item_variant_id', 'inventory_lot_id', 'item_serial_id', 'from_storage_location_id', 'suggested_storage_location_id', 'to_storage_location_id', 'stock_condition', 'quantity', 'status', 'assigned_to', 'posted_by', 'posted_at', 'stock_movement_id', 'version'];

    protected function casts(): array
    {
        return [
            'status' => PutawayTaskStatus::class,
            'stock_condition' => \App\Domains\Inventory\Enums\StockCondition::class,
            'posted_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Catalog\Models\Item::class, 'item_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Organization\Models\Warehouse::class, 'warehouse_id');
    }
}
