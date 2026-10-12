<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Models;

use App\Domains\Inventory\Enums\ReversalStatus;
use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Request → approval → posting of a ledger reversal (Chapter 07 §7.11). */
final class StockMovementReversal extends BaseModel
{
    protected $table = 'stock_movement_reversals';

    protected $fillable = ['document_number', 'original_movement_id', 'reversal_movement_id', 'requested_by', 'requested_at', 'approved_by', 'approved_at',
        'reason_lookup_value_id', 'reason', 'lines', 'status'];

    protected function casts(): array
    {
        return [
            'status' => ReversalStatus::class,
            'lines' => 'array',
            'requested_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
        ];
    }

    public function original(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'original_movement_id');
    }
}
