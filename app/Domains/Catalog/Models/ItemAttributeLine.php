<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Models;

use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Attribute values allowed for an item's variants. */
final class ItemAttributeLine extends BaseModel
{
    protected $table = 'item_attribute_lines';

    protected $fillable = ['item_id', 'item_attribute_id', 'value_ids'];

    protected function casts(): array
    {
        return [
            'value_ids' => 'array',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(ItemAttribute::class, 'item_attribute_id');
    }
}
