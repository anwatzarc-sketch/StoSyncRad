<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Models;

use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Packaging (box, carton, pallet) containing a base-unit quantity. */
final class ItemPackaging extends BaseModel
{
    protected $table = 'item_packagings';

    protected $fillable = ['item_id', 'name', 'contained_qty', 'packaging_type_lookup_value_id', 'weight', 'length', 'width', 'height', 'is_default_purchase', 'is_default_sales', 'is_default_transfer'];

    protected function casts(): array
    {
        return [
            'is_default_purchase' => 'boolean',
            'is_default_sales' => 'boolean',
            'is_default_transfer' => 'boolean',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }
}
