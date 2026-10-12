<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Models;

use App\Domains\Inventory\Enums\SerialStatus;
use App\Domains\Inventory\Enums\StockCondition;
use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Individually tracked unit; current position is a projection of the ledger (Chapter 07 §7.8). */
final class ItemSerial extends BaseModel
{
    protected $table = 'item_serials';

    protected $fillable = ['item_id', 'item_variant_id', 'serial_number', 'inventory_lot_id', 'lifecycle_status', 'current_inventory_location_id', 'current_condition', 'current_warehouse_id', 'warranty_until', 'attributes', 'version'];

    protected function casts(): array
    {
        return [
            'lifecycle_status' => SerialStatus::class,
            'current_condition' => StockCondition::class,
            'warranty_until' => 'immutable_date',
            'attributes' => 'array',
            'version' => 'integer',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Catalog\Models\Item::class, 'item_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'inventory_lot_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Organization\Models\InventoryLocation::class, 'current_inventory_location_id');
    }
}
