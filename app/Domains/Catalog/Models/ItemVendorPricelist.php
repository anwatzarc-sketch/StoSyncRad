<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Models;

use App\Domains\Catalog\Schemas\ItemVendorPricelistSchema;
use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Supplier price and product reference for an item. */
final class ItemVendorPricelist extends BaseModel
{
    protected $table = 'item_vendor_pricelists';

    protected static ?string $schema = ItemVendorPricelistSchema::class;

    protected function casts(): array
    {
        return [
            'valid_from' => 'immutable_date',
            'valid_to' => 'immutable_date',
            'lead_time_days' => 'integer',
            'is_preferred' => 'boolean',
            'last_purchased_at' => 'immutable_datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Partners\Models\Partner::class, 'supplier_id');
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'uom_id');
    }
}
