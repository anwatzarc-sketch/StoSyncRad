<?php

declare(strict_types=1);

namespace App\Domains\Receiving\Models;

use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Goods receipt line. */
final class GoodsReceiptLine extends BaseModel
{
    protected $table = 'goods_receipt_lines';

    protected $fillable = ['goods_receipt_id', 'line_no', 'purchase_order_line_id', 'item_id', 'item_variant_id', 'expected_quantity', 'received_quantity', 'accepted_quantity', 'rejected_quantity', 'uom_id', 'conversion_factor_snapshot', 'accepted_base_quantity', 'rejected_base_quantity', 'lot_number', 'inventory_lot_id', 'manufacture_date', 'expiry_date', 'serial_numbers', 'destination_storage_location_id', 'receiving_condition', 'resulting_condition', 'rejection_reason_lookup_value_id', 'inspection_notes', 'unit_cost', 'quality_check_id'];

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'manufacture_date' => 'immutable_date',
            'expiry_date' => 'immutable_date',
            'serial_numbers' => 'array',
            'receiving_condition' => \App\Domains\Receiving\Enums\ReceivingCondition::class,
            'resulting_condition' => \App\Domains\Inventory\Enums\StockCondition::class,
        ];
    }

    public function header(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Catalog\Models\Item::class, 'item_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Inventory\Models\InventoryLot::class, 'inventory_lot_id');
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Catalog\Models\UnitOfMeasure::class, 'uom_id');
    }
}
