<?php

declare(strict_types=1);

namespace App\Domains\Transfers\Models;

use App\Domains\Transfers\Enums\TransferEventStatus;
use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One receipt posting event of a transfer. */
final class TransferReceipt extends BaseModel
{
    protected $table = 'transfer_receipts';

    protected $fillable = ['document_number', 'transfer_order_id', 'status', 'received_by', 'received_at', 'stock_movement_id', 'notes'];

    protected function casts(): array
    {
        return [
            'status' => TransferEventStatus::class,
            'received_at' => 'immutable_datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(TransferReceiptLine::class, 'transfer_receipt_id');
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(TransferOrder::class, 'transfer_order_id');
    }
}
