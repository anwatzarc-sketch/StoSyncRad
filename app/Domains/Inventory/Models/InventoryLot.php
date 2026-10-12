<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Models;

use App\Domains\Inventory\Enums\LotLockStatus;
use App\Domains\Inventory\Enums\LotQcStatus;
use App\Domains\Inventory\Enums\LotStatus;
use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Lot/batch with expiry, QC and lock state (Chapter 07 §7.7). */
final class InventoryLot extends BaseModel
{
    protected $table = 'inventory_lots';

    protected $fillable = ['item_id', 'item_variant_id', 'lot_number', 'supplier_id', 'supplier_lot_reference', 'manufacture_date', 'expiry_date', 'best_before_date', 'removal_date', 'alert_date', 'origin_document_type', 'origin_document_id', 'qc_status', 'qc_released_by', 'qc_released_at', 'lock_status', 'lock_reference_type', 'lock_reference_id', 'status', 'attributes', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return [
            'manufacture_date' => 'immutable_date',
            'expiry_date' => 'immutable_date',
            'best_before_date' => 'immutable_date',
            'removal_date' => 'immutable_date',
            'alert_date' => 'immutable_date',
            'qc_status' => LotQcStatus::class,
            'qc_released_at' => 'immutable_datetime',
            'lock_status' => LotLockStatus::class,
            'status' => LotStatus::class,
            'attributes' => 'array',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Catalog\Models\Item::class, 'item_id');
    }

    public function isLocked(): bool
    {
        return $this->lock_status !== LotLockStatus::UNLOCKED;
    }

    public function isReleased(): bool
    {
        return $this->qc_status === LotQcStatus::RELEASED || $this->qc_status === LotQcStatus::CONDITIONAL;
    }

    public function isExpiredOn(\Carbon\CarbonInterface $date): bool
    {
        return $this->expiry_date !== null && $this->expiry_date->lte($date->startOfDay());
    }
}
