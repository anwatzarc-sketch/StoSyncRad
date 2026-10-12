<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Models;

use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Item-specific conversion: 1 unit = factor × base unit. */
final class ItemUomConversion extends BaseModel
{
    protected $table = 'item_uom_conversions';

    protected $fillable = ['item_id', 'uom_id', 'conversion_factor', 'rounding_precision', 'is_active'];

    protected function casts(): array
    {
        return [
            'rounding_precision' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'uom_id');
    }
}
