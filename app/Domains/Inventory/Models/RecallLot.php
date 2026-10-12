<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Models;

use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Lot covered by a recall. */
final class RecallLot extends BaseModel
{
    protected $table = 'recall_lots';

    protected $fillable = ['recall_id', 'inventory_lot_id', 'previous_lock_status'];

    public function recall(): BelongsTo
    {
        return $this->belongsTo(Recall::class, 'recall_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'inventory_lot_id');
    }
}
