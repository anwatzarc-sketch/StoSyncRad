<?php

declare(strict_types=1);

namespace App\Domains\Sales\Models;

use App\Domains\Fulfillment\Models\SalesOrder;
use App\Domains\Organization\Enums\DocumentType;
use App\Domains\Partners\Models\Partner;
use App\Domains\Sales\Enums\QuotationStatus;
use App\Support\Eloquent\BaseModel;
use App\Support\Enums\Priority;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Customer quotation: requested in the portal, priced by staff, accepted once into a sales order. */
final class Quotation extends BaseModel
{
    protected $table = 'quotations';

    protected $fillable = ['document_number', 'branch_id', 'document_date', 'reference', 'notes', 'priority', 'created_by', 'updated_by', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'posted_by', 'posted_at', 'cancelled_by', 'cancelled_at', 'cancellation_reason_lookup_value_id', 'cancellation_reason', 'version', 'warehouse_id', 'status', 'customer_partner_id', 'requested_by_account_id', 'currency', 'subtotal', 'total', 'valid_until', 'customer_note', 'staff_note', 'revision_note', 'revision', 'sent_by', 'sent_at', 'responded_by_account_id', 'responded_at', 'sales_order_id', 'idempotency_key'];

    protected $hidden = ['idempotency_key'];

    protected function casts(): array
    {
        return [
            'document_date' => 'immutable_date',
            'version' => 'integer',
            'priority' => Priority::class,
            'submitted_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'posted_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'status' => QuotationStatus::class,
            'valid_until' => 'immutable_date',
            'sent_at' => 'immutable_datetime',
            'responded_at' => 'immutable_datetime',
            'revision' => 'integer',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QuotationLine::class, 'quotation_id')->orderBy('line_no');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'customer_partner_id');
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    /** Document type key used by numbering and attachments. */
    public static function documentType(): DocumentType
    {
        return DocumentType::QUOTATION;
    }
}
