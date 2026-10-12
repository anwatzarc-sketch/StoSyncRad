<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Models;

use App\Domains\Inventory\Enums\ReservationReferenceType;
use App\Domains\Inventory\Enums\ReservationStatus;
use App\Support\Eloquent\BaseModel;
use App\Support\Math\Decimal;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Reservation of eligible stock for a document line (Chapter 07 §7.6). */
final class StockReservation extends BaseModel
{
    protected $table = 'stock_reservations';

    protected $fillable = ['reference_type', 'reference_id', 'reference_line_id', 'inventory_balance_id', 'item_id', 'item_variant_id', 'inventory_location_id', 'inventory_lot_id', 'item_serial_id', 'warehouse_id', 'quantity_reserved', 'quantity_consumed', 'quantity_released', 'status', 'expires_at', 'created_by', 'released_by', 'released_at', 'release_reason', 'version'];

    protected function casts(): array
    {
        return [
            'reference_type' => ReservationReferenceType::class,
            'status' => ReservationStatus::class,
            'expires_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public function balance(): BelongsTo
    {
        return $this->belongsTo(InventoryBalance::class, 'inventory_balance_id');
    }

    public function outstanding(): string
    {
        return Decimal::sub(Decimal::sub($this->quantity_reserved, $this->quantity_consumed), $this->quantity_released);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [ReservationStatus::ACTIVE, ReservationStatus::PARTIALLY_CONSUMED], true);
    }
}
