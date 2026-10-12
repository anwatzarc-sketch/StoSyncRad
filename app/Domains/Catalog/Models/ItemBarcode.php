<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Models;

use App\Domains\Catalog\Enums\BarcodeSymbology;
use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Barcode identifying an item, variant or packaging (globally unique). */
final class ItemBarcode extends BaseModel
{
    protected $table = 'item_barcodes';

    protected $fillable = ['item_id', 'item_variant_id', 'item_packaging_id', 'barcode', 'symbology', 'is_primary'];

    protected function casts(): array
    {
        return [
            'symbology' => BarcodeSymbology::class,
            'is_primary' => 'boolean',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ItemVariant::class, 'item_variant_id');
    }

    public function packaging(): BelongsTo
    {
        return $this->belongsTo(ItemPackaging::class, 'item_packaging_id');
    }
}
