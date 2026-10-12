<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Models;

use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Customer return line. */
final class CustomerReturnLine extends BaseModel
{
    protected $table = 'customer_return_lines';

    protected $fillable = ['customer_return_id', 'line_no', 'sales_order_line_id', 'item_id', 'item_variant_id', 'inventory_lot_id', 'item_serial_id', 'base_quantity', 'resulting_condition', 'destination_storage_location_id', 'inspection_notes'];

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'resulting_condition' => \App\Domains\Inventory\Enums\StockCondition::class,
        ];
    }

    public function header(): BelongsTo
    {
        return $this->belongsTo(CustomerReturn::class, 'customer_return_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Catalog\Models\Item::class, 'item_id');
    }
}
