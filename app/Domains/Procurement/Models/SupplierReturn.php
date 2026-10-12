<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Models;

use App\Domains\Procurement\Enums\SupplierReturnStatus;
use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Supplier return (Chapter 08 §8.3). */
final class SupplierReturn extends BaseModel
{
    protected $table = 'supplier_returns';

    protected $fillable = ['document_number', 'warehouse_id', 'branch_id', 'document_date', 'reference', 'notes', 'priority', 'created_by', 'updated_by', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'posted_by', 'posted_at', 'cancelled_by', 'cancelled_at', 'cancellation_reason_lookup_value_id', 'cancellation_reason', 'version', 'status', 'supplier_id', 'goods_receipt_id', 'reason_lookup_value_id', 'reason', 'stock_movement_id'];

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
            'status' => SupplierReturnStatus::class,
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SupplierReturnLine::class, 'supplier_return_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Partners\Models\Partner::class, 'supplier_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Organization\Models\Warehouse::class, 'warehouse_id');
    }

    public static function documentType(): \App\Domains\Organization\Enums\DocumentType
    {
        return \App\Domains\Organization\Enums\DocumentType::SUPPLIER_RETURN;
    }
}
