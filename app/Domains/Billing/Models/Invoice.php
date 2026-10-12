<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Domains\Billing\Enums\InvoiceStatus;
use App\Domains\Fulfillment\Models\SalesOrder;
use App\Domains\Organization\Enums\DocumentType;
use App\Domains\Partners\Models\Partner;
use App\Support\Eloquent\BaseModel;
use App\Support\Enums\Priority;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Customer invoice, the authoritative bill. Issued invoices never change; balance_due moves only through finance payment allocations. */
final class Invoice extends BaseModel
{
    protected $table = 'invoices';

    protected $fillable = ['document_number', 'branch_id', 'document_date', 'reference', 'notes', 'priority', 'created_by', 'updated_by', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'posted_by', 'posted_at', 'cancelled_by', 'cancelled_at', 'cancellation_reason_lookup_value_id', 'cancellation_reason', 'version', 'status', 'customer_partner_id', 'sales_order_id', 'shipment_id', 'currency', 'issue_date', 'due_date', 'subtotal', 'tax_rate', 'tax_amount', 'total', 'amount_paid', 'balance_due', 'issued_by', 'issued_at', 'voided_by', 'voided_at', 'void_reason'];

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
            'status' => InvoiceStatus::class,
            'issue_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'issued_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class, 'invoice_id')->orderBy('line_no');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'customer_partner_id');
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class, 'invoice_id')->orderBy('created_at');
    }

    /** Document type key used by numbering and attachments. */
    public static function documentType(): DocumentType
    {
        return DocumentType::INVOICE;
    }
}
