<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Models;

use App\Domains\Inventory\Enums\MovementStatus;
use App\Domains\Inventory\Enums\MovementType;
use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Append-only ledger header — one per posting event (Chapter 07 §7.4). */
final class StockMovement extends BaseModel
{
    protected $table = 'stock_movements';

    protected $fillable = ['movement_number', 'movement_type', 'source_document_type', 'source_document_id', 'source_document_number', 'posting_event_id', 'idempotency_key_id', 'status', 'posting_actor_id', 'posted_at', 'business_date', 'reversal_of_movement_id', 'reason_lookup_value_id', 'reason_text', 'correlation_id', 'warehouse_id', 'counterparty_warehouse_id', 'partner_id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'movement_type' => MovementType::class,
            'source_document_type' => \App\Domains\Organization\Enums\DocumentType::class,
            'status' => MovementStatus::class,
            'posted_at' => 'immutable_datetime',
            'business_date' => 'immutable_date',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockMovementLine::class, 'stock_movement_id');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'reversal_of_movement_id');
    }
}
