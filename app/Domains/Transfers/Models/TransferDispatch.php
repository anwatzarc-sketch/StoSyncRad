<?php

declare(strict_types=1);

namespace App\Domains\Transfers\Models;

use App\Domains\Transfers\Enums\TransferEventStatus;
use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One dispatch posting event of a transfer. */
final class TransferDispatch extends BaseModel
{
    protected $table = 'transfer_dispatches';

    protected $fillable = ['document_number', 'transfer_order_id', 'status', 'dispatched_by', 'dispatched_at', 'carrier_partner_id', 'tracking_reference', 'seal_reference', 'vehicle_reference', 'expected_arrival', 'stock_movement_id', 'notes'];

    protected function casts(): array
    {
        return [
            'status' => TransferEventStatus::class,
            'dispatched_at' => 'immutable_datetime',
            'expected_arrival' => 'immutable_date',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(TransferDispatchLine::class, 'transfer_dispatch_id');
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(TransferOrder::class, 'transfer_order_id');
    }
}
