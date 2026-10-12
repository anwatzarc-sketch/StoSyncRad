<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Models;

use App\Domains\Fulfillment\Enums\PickLineStatus;
use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One suggested pick. */
final class PickListLine extends BaseModel
{
    protected $table = 'pick_list_lines';

    protected $fillable = ['pick_list_id', 'line_no', 'source_line_id', 'item_id', 'item_variant_id', 'storage_location_id', 'inventory_lot_id', 'item_serial_id', 'stock_reservation_id', 'suggested_base_quantity', 'picked_base_quantity', 'status', 'short_pick_reason', 'sort_order'];

    protected function casts(): array
    {
        return [
            'status' => PickLineStatus::class,
            'line_no' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function list(): BelongsTo
    {
        return $this->belongsTo(PickList::class, 'pick_list_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Catalog\Models\Item::class, 'item_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Organization\Models\StorageLocation::class, 'storage_location_id');
    }
}
