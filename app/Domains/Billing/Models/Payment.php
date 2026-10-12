<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Enums\PaymentStatus;
use App\Domains\Organization\Enums\DocumentType;
use App\Domains\Partners\Models\Partner;
use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A payment verified and recorded by finance (append-only; voiding keeps the record). */
final class Payment extends BaseModel
{
    protected $table = 'payments';

    protected $fillable = ['document_number', 'customer_partner_id', 'amount', 'currency', 'received_on', 'method', 'bank_reference', 'payment_confirmation_id', 'notes', 'status', 'recorded_by', 'voided_by', 'voided_at', 'void_reason'];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'method' => PaymentMethod::class,
            'received_on' => 'immutable_date',
            'voided_at' => 'immutable_datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'customer_partner_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class, 'payment_id')->orderBy('created_at');
    }

    /** Document type key used by numbering and attachments. */
    public static function documentType(): DocumentType
    {
        return DocumentType::PAYMENT;
    }
}
