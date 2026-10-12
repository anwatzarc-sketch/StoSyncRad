<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Part of a payment applied to an invoice (append-only; reversals are marked, not deleted). */
final class PaymentAllocation extends BaseModel
{
    protected $table = 'payment_allocations';

    protected $fillable = ['payment_id', 'invoice_id', 'amount', 'allocated_by', 'created_at', 'reversed_by', 'reversed_at', 'reversal_reason'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'reversed_at' => 'immutable_datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }
}
