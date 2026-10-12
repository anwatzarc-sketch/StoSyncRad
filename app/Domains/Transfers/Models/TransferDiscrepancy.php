<?php

declare(strict_types=1);

namespace App\Domains\Transfers\Models;

use App\Domains\Transfers\Enums\DiscrepancyStatus;
use App\Domains\Transfers\Enums\DiscrepancyType;
use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Shortage/excess/damage/mismatch recorded at transfer receipt; never alters the dispatch. */
final class TransferDiscrepancy extends BaseModel
{
    protected $table = 'transfer_discrepancies';

    protected $fillable = ['document_number', 'transfer_order_id', 'transfer_dispatch_line_id', 'transfer_receipt_line_id', 'item_id', 'inventory_lot_id', 'item_serial_id', 'discrepancy_type', 'base_quantity', 'resolved_base_quantity', 'status', 'reason_lookup_value_id', 'notes', 'reported_by', 'version'];

    protected function casts(): array
    {
        return [
            'discrepancy_type' => DiscrepancyType::class,
            'status' => DiscrepancyStatus::class,
            'version' => 'integer',
        ];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(TransferOrder::class, 'transfer_order_id');
    }

    public function resolutions(): HasMany
    {
        return $this->hasMany(TransferDiscrepancyResolution::class, 'transfer_discrepancy_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Catalog\Models\Item::class, 'item_id');
    }
}
