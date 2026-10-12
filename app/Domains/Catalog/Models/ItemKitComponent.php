<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Models;

use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Component of a kit item. */
final class ItemKitComponent extends BaseModel
{
    protected $table = 'item_kit_components';

    protected $fillable = ['kit_item_id', 'component_item_id', 'component_variant_id', 'quantity', 'uom_id'];

    public function kit(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'kit_item_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'component_item_id');
    }
}
