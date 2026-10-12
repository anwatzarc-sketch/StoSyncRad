<?php

declare(strict_types=1);

namespace App\Domains\Sales\Models;

use App\Domains\Catalog\Models\Item;
use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A quotation line (quantities in the item base unit). */
final class QuotationLine extends BaseModel
{
    protected $table = 'quotation_lines';

    protected $fillable = ['quotation_id', 'line_no', 'item_id', 'uom_id', 'quantity', 'unit_price', 'line_total', 'notes'];

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }
}
