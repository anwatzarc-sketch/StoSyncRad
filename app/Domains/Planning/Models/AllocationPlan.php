<?php

declare(strict_types=1);

namespace App\Domains\Planning\Models;

use App\Domains\Planning\Enums\AllocationPlanStatus;
use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** HQ allocation plan that generates transfers (Chapter 08 §8.1). */
final class AllocationPlan extends BaseModel
{
    protected $table = 'allocation_plans';

    protected $fillable = ['document_number', 'warehouse_id', 'branch_id', 'document_date', 'reference', 'notes', 'priority', 'created_by', 'updated_by', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'posted_by', 'posted_at', 'cancelled_by', 'cancelled_at', 'cancellation_reason_lookup_value_id', 'cancellation_reason', 'version', 'status', 'policy', 'period_start', 'period_end', 'released_by', 'released_at'];

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
            'status' => AllocationPlanStatus::class,
            'policy' => \App\Domains\Planning\Enums\AllocationPolicy::class,
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'released_at' => 'immutable_datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(AllocationPlanLine::class, 'allocation_plan_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Organization\Models\Warehouse::class, 'warehouse_id');
    }

    /** Document type key used by approvals, SoD, numbering and attachments. */
    public static function documentType(): \App\Domains\Organization\Enums\DocumentType
    {
        return \App\Domains\Organization\Enums\DocumentType::ALLOCATION_PLAN;
    }
}
