<?php

declare(strict_types=1);

namespace App\Domains\Stocktaking\Models;

use App\Domains\Stocktaking\Enums\StockAdjustmentStatus;
use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Approved stock adjustment (Chapter 08 §8.11). */
final class StockAdjustment extends BaseModel
{
    protected $table = 'stock_adjustments';

    protected $fillable = ['document_number', 'warehouse_id', 'branch_id', 'document_date', 'reference', 'notes', 'priority', 'created_by', 'updated_by', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'posted_by', 'posted_at', 'cancelled_by', 'cancelled_at', 'cancellation_reason_lookup_value_id', 'cancellation_reason', 'version', 'status', 'adjustment_type', 'source_stocktake_id', 'reason_lookup_value_id', 'reason', 'total_value', 'rejected_by', 'rejected_at', 'rejection_reason', 'stock_movement_id'];

    protected function casts(): array
    {
        return [
            'document_date' => 'immutable_date',
            'submitted_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'posted_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'version' => 'integer',
            'priority' => \App\Support\Enums\Priority::class,
            'status' => StockAdjustmentStatus::class,
            'adjustment_type' => \App\Domains\Stocktaking\Enums\AdjustmentType::class,
            'rejected_at' => 'immutable_datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockAdjustmentLine::class, 'stock_adjustment_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Organization\Models\Warehouse::class, 'warehouse_id');
    }

    /** Document type key used by approvals, SoD, numbering and attachments. */
    public static function documentType(): \App\Domains\Organization\Enums\DocumentType
    {
        return \App\Domains\Organization\Enums\DocumentType::STOCK_ADJUSTMENT;
    }
}
