<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Models;

use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Internal return line. */
final class InternalReturnLine extends BaseModel
{
    protected $table = 'internal_return_lines';

    protected $fillable = ['internal_return_id', 'line_no', 'stock_issue_line_id', 'item_id', 'inventory_lot_id', 'item_serial_id', 'base_quantity', 'resulting_condition', 'destination_storage_location_id', 'inspection_notes'];

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'resulting_condition' => \App\Domains\Inventory\Enums\StockCondition::class,
        ];
    }

    public function header(): BelongsTo
    {
        return $this->belongsTo(InternalReturn::class, 'internal_return_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Catalog\Models\Item::class, 'item_id');
    }
}
