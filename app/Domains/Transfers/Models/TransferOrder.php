<?php

declare(strict_types=1);

namespace App\Domains\Transfers\Models;

use App\Domains\Transfers\Enums\TransferStatus;
use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Inter-branch transfer order (Chapter 08 §8.4). */
final class TransferOrder extends BaseModel
{
    protected $table = 'transfer_orders';

    protected $fillable = ['document_number', 'branch_id', 'document_date', 'reference', 'notes', 'priority', 'created_by', 'updated_by', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'posted_by', 'posted_at', 'cancelled_by', 'cancelled_at', 'cancellation_reason_lookup_value_id', 'cancellation_reason', 'version', 'status', 'source_warehouse_id', 'destination_warehouse_id', 'requested_by', 'required_by', 'reason', 'allocation_plan_id', 'carrier_partner_id', 'closed_by', 'closed_at'];

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
            'status' => TransferStatus::class,
            'required_by' => 'immutable_date',
            'closed_at' => 'immutable_datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(TransferOrderLine::class, 'transfer_order_id');
    }

    public function dispatches(): HasMany
    {
        return $this->hasMany(TransferDispatch::class, 'transfer_order_id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(TransferReceipt::class, 'transfer_order_id');
    }

    public function discrepancies(): HasMany
    {
        return $this->hasMany(TransferDiscrepancy::class, 'transfer_order_id');
    }

    public function sourceWarehouse(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Organization\Models\Warehouse::class, 'source_warehouse_id');
    }

    public function destinationWarehouse(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Organization\Models\Warehouse::class, 'destination_warehouse_id');
    }

    /** Document type key used by approvals, SoD, numbering and attachments. */
    public static function documentType(): \App\Domains\Organization\Enums\DocumentType
    {
        return \App\Domains\Organization\Enums\DocumentType::TRANSFER_ORDER;
    }
}
