<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Models;

use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Item/lot/serial packed into a package. */
final class ShipmentPackageLine extends BaseModel
{
    protected $table = 'shipment_package_lines';

    protected $fillable = ['shipment_package_id', 'sales_order_line_id', 'item_id', 'item_variant_id', 'storage_location_id', 'inventory_lot_id', 'item_serial_id', 'stock_reservation_id', 'base_quantity'];

    public function package(): BelongsTo
    {
        return $this->belongsTo(ShipmentPackage::class, 'shipment_package_id');
    }
}
