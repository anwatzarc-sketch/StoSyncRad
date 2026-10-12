<?php

declare(strict_types=1);

namespace App\Domains\Stocktaking\Models;

use App\Domains\Stocktaking\Enums\StocktakeStatus;
use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Full, cycle or spot stocktake (Chapter 08 §8.10). */
final class Stocktake extends BaseModel
{
    protected $table = 'stocktakes';

    protected $fillable = ['document_number', 'warehouse_id', 'branch_id', 'document_date', 'reference', 'notes', 'priority', 'created_by', 'updated_by', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'posted_by', 'posted_at', 'cancelled_by', 'cancelled_at', 'cancellation_reason_lookup_value_id', 'cancellation_reason', 'version', 'status', 'stocktake_type', 'freeze_policy', 'is_blind', 'scope', 'snapshot_at', 'scheduled_for', 'reviewed_by', 'reviewed_at', 'stock_adjustment_id', 'instructions'];

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
            'status' => StocktakeStatus::class,
            'stocktake_type' => \App\Domains\Stocktaking\Enums\StocktakeType::class,
            'freeze_policy' => \App\Domains\Stocktaking\Enums\StocktakeFreezePolicy::class,
            'is_blind' => 'boolean',
            'scope' => 'array',
            'snapshot_at' => 'immutable_datetime',
            'scheduled_for' => 'immutable_date',
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StocktakeLine::class, 'stocktake_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Organization\Models\Warehouse::class, 'warehouse_id');
    }

    /** Document type key used by approvals, SoD, numbering and attachments. */
    public static function documentType(): \App\Domains\Organization\Enums\DocumentType
    {
        return \App\Domains\Organization\Enums\DocumentType::STOCKTAKE;
    }
}
