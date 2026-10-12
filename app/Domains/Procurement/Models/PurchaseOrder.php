<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Models;

use App\Domains\Procurement\Enums\PurchaseOrderStatus;
use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Purchase order (Chapter 08 §8.2). */
final class PurchaseOrder extends BaseModel
{
    protected $table = 'purchase_orders';

    protected $fillable = ['document_number', 'warehouse_id', 'branch_id', 'document_date', 'reference', 'notes', 'priority', 'created_by', 'updated_by', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'posted_by', 'posted_at', 'cancelled_by', 'cancelled_at', 'cancellation_reason_lookup_value_id', 'cancellation_reason', 'version', 'status', 'supplier_id', 'purchase_request_id', 'expected_delivery_date', 'currency', 'exchange_rate', 'incoterm_lookup_value_id', 'payment_terms_lookup_value_id', 'supplier_reference', 'delivery_notes', 'subtotal', 'charges_total', 'total', 'revision', 'sent_by', 'sent_at', 'closed_by', 'closed_at', 'rejected_by', 'rejected_at', 'rejection_reason'];

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
            'status' => PurchaseOrderStatus::class,
            'expected_delivery_date' => 'immutable_date',
            'sent_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'revision' => 'integer',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class, 'purchase_order_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Partners\Models\Partner::class, 'supplier_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Organization\Models\Warehouse::class, 'warehouse_id');
    }

    /** Document type key used by approvals, SoD, numbering and attachments. */
    public static function documentType(): \App\Domains\Organization\Enums\DocumentType
    {
        return \App\Domains\Organization\Enums\DocumentType::PURCHASE_ORDER;
    }
}
