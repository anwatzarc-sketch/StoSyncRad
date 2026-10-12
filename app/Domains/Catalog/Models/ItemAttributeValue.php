<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Models;

use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One value of a variant attribute. */
final class ItemAttributeValue extends BaseModel
{
    protected $table = 'item_attribute_values';

    protected $fillable = ['item_attribute_id', 'code', 'name', 'html_color', 'sort_order'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(ItemAttribute::class, 'item_attribute_id');
    }
}
