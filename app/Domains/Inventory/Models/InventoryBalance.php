<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Models;

use App\Domains\Inventory\Enums\StockCondition;
use App\Support\Eloquent\BaseModel;
use App\Support\Math\Decimal;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Balance projection per stock position — never a source of truth (Chapter 07 §7.5). */
final class InventoryBalance extends BaseModel
{
    protected $table = 'inventory_balances';

    protected $fillable = ['item_id', 'item_variant_id', 'inventory_location_id', 'inventory_lot_id', 'stock_condition', 'owner_partner_id', 'package_id', 'warehouse_id', 'quantity_on_hand', 'quantity_reserved', 'unit_cost_projection', 'total_value_projection', 'allow_negative', 'last_movement_at', 'last_counted_at', 'is_frozen', 'frozen_by_stocktake_id', 'version'];

    protected function casts(): array
    {
        return [
            'stock_condition' => StockCondition::class,
            'allow_negative' => 'boolean',
            'is_frozen' => 'boolean',
            'last_movement_at' => 'immutable_datetime',
            'last_counted_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Catalog\Models\Item::class, 'item_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Organization\Models\InventoryLocation::class, 'inventory_location_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'inventory_lot_id');
    }

    /** On hand minus active reservations (condition/lot eligibility is checked by AvailabilityService). */
    public function unreserved(): string
    {
        return Decimal::sub($this->quantity_on_hand, $this->quantity_reserved);
    }
}
