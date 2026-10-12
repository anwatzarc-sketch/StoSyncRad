<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Models;

use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ordered item image. */
final class ItemImage extends BaseModel
{
    protected $table = 'item_images';

    protected $fillable = ['item_id', 'item_variant_id', 'path', 'mime', 'sort_order', 'is_primary'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_primary' => 'boolean',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }
}
