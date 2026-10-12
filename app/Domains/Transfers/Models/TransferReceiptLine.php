<?php

declare(strict_types=1);

namespace App\Domains\Transfers\Models;

use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Received quantities against one dispatch line. */
final class TransferReceiptLine extends BaseModel
{
    protected $table = 'transfer_receipt_lines';

    protected $fillable = ['transfer_receipt_id', 'transfer_dispatch_line_id', 'accepted_base_quantity', 'damaged_base_quantity', 'shortage_base_quantity', 'excess_base_quantity', 'receiving_condition', 'destination_storage_location_id', 'scanned_lot_number', 'scanned_serial_number', 'discrepancy_reason_lookup_value_id', 'notes'];

    protected function casts(): array
    {
        return [
            'receiving_condition' => \App\Domains\Receiving\Enums\ReceivingCondition::class,
        ];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(TransferReceipt::class, 'transfer_receipt_id');
    }

    public function dispatchLine(): BelongsTo
    {
        return $this->belongsTo(TransferDispatchLine::class, 'transfer_dispatch_line_id');
    }
}
