<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Models;

use App\Domains\Fulfillment\Enums\ShipmentStatus;
use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Packed shipment of a sales order. */
final class Shipment extends BaseModel
{
    protected $table = 'shipments';

    protected $fillable = ['document_number', 'warehouse_id', 'sales_order_id', 'status', 'carrier_partner_id', 'carrier_service_lookup_value_id', 'tracking_number', 'packed_by', 'packed_at', 'handed_over_by', 'handed_over_at', 'delivered_at', 'stock_movement_id', 'version'];

    protected function casts(): array
    {
        return [
            'status' => ShipmentStatus::class,
            'packed_at' => 'immutable_datetime',
            'handed_over_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public function packages(): HasMany
    {
        return $this->hasMany(ShipmentPackage::class, 'shipment_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ShipmentEvent::class, 'shipment_id');
    }
}
