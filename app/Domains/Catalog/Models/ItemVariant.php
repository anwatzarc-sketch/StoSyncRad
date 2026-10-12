<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Models;

use App\Domains\Catalog\Enums\ItemStatus;
use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Concrete variant of an item; stock is held per variant when variants exist. */
final class ItemVariant extends BaseModel
{
    protected $table = 'item_variants';

    protected $fillable = ['item_id', 'combination_hash', 'attribute_values', 'sku', 'name_suffix', 'weight', 'volume', 'extra_list_price', 'status', 'first_posted_at'];

    protected function casts(): array
    {
        return [
            'attribute_values' => 'array',
            'status' => ItemStatus::class,
            'first_posted_at' => 'immutable_datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }
}
