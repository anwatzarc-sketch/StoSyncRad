<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Models;

use App\Domains\Inventory\Enums\ReconciliationFindingStatus;
use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A position whose projection differs from the ledger. */
final class ReconciliationFinding extends BaseModel
{
    protected $table = 'reconciliation_findings';

    protected $fillable = ['reconciliation_run_id', 'item_id', 'item_variant_id', 'inventory_location_id', 'inventory_lot_id', 'stock_condition', 'ledger_quantity', 'projection_quantity', 'difference', 'status', 'resolved_by', 'resolved_at', 'resolution_note'];

    protected function casts(): array
    {
        return [
            'status' => ReconciliationFindingStatus::class,
            'resolved_at' => 'immutable_datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ReconciliationRun::class, 'reconciliation_run_id');
    }
}
