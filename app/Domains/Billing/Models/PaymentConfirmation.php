<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Domains\Billing\Enums\PaymentConfirmationStatus;
use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Fulfillment\Models\SalesOrder;
use App\Domains\Organization\Enums\DocumentType;
use App\Domains\Partners\Models\Partner;
use App\Domains\Platform\Enums\AttachableType;
use App\Domains\Platform\Models\DocumentAttachment;
use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A customer claim that they paid, with private evidence. Supporting evidence only: finance verifies the funds before a payment is recorded. */
final class PaymentConfirmation extends BaseModel
{
    protected $table = 'payment_confirmations';

    protected $fillable = ['document_number', 'customer_partner_id', 'submitted_by_account_id', 'invoice_id', 'sales_order_id', 'amount', 'currency', 'paid_on', 'method', 'transaction_reference', 'payer_name', 'bank_name', 'customer_note', 'status', 'duplicate_of_id', 'review_note', 'reviewed_by', 'reviewed_at', 'payment_id', 'idempotency_key', 'version'];

    protected $hidden = ['idempotency_key'];

    protected function casts(): array
    {
        return [
            'status' => PaymentConfirmationStatus::class,
            'method' => PaymentMethod::class,
            'paid_on' => 'immutable_date',
            'reviewed_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'customer_partner_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    /** Evidence files (private attachments). */
    public function evidence(): HasMany
    {
        return $this->hasMany(DocumentAttachment::class, 'document_id')->where('document_type', AttachableType::PAYMENT_CONFIRMATION->value)->orderBy('created_at');
    }

    /** Document type key used by numbering and attachments. */
    public static function documentType(): DocumentType
    {
        return DocumentType::PAYMENT_CONFIRMATION;
    }
}
