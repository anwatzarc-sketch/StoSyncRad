<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Models;

use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Opening balance line. */
final class OpeningBalanceLine extends BaseModel
{
    protected $table = 'opening_balance_lines';

    protected $fillable = ['document_id', 'line_no', 'item_id', 'item_variant_id', 'notes', 'storage_location_id', 'quantity', 'unit_cost', 'lot_number', 'expiry_date', 'manufacture_date', 'serial_numbers', 'stock_condition'];

    protected function casts(): array
    {
        return [
            'serial_numbers' => 'array',
            'expiry_date' => 'immutable_date',
            'manufacture_date' => 'immutable_date',
            'stock_condition' => \App\Domains\Inventory\Enums\StockCondition::class,
            'line_no' => 'integer',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(OpeningBalanceDocument::class, 'document_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Catalog\Models\Item::class, 'item_id');
    }
}
