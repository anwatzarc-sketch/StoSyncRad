<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Models;

use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Relocation line. */
final class RelocationLine extends BaseModel
{
    protected $table = 'relocation_lines';

    protected $fillable = ['document_id', 'line_no', 'item_id', 'item_variant_id', 'notes', 'from_storage_location_id', 'to_storage_location_id', 'inventory_lot_id', 'item_serial_id', 'stock_condition', 'quantity'];

    protected function casts(): array
    {
        return [
            'stock_condition' => \App\Domains\Inventory\Enums\StockCondition::class,
            'line_no' => 'integer',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Relocation::class, 'document_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Catalog\Models\Item::class, 'item_id');
    }
}
