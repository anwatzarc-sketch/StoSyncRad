<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Models;

use App\Domains\Inventory\Enums\QcOutcome;
use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Recorded quality inspection (Chapter 07 §7.7). */
final class QualityCheck extends BaseModel
{
    protected $table = 'quality_checks';

    protected $fillable = ['document_type', 'document_id', 'document_line_id', 'item_id', 'inventory_lot_id', 'warehouse_id', 'qc_checklist_template_id', 'results', 'outcome', 'inspected_quantity', 'passed_quantity', 'failed_quantity', 'notes', 'inspector_id', 'inspected_at'];

    protected function casts(): array
    {
        return [
            'document_type' => \App\Domains\Organization\Enums\DocumentType::class,
            'results' => 'array',
            'outcome' => QcOutcome::class,
            'inspected_at' => 'immutable_datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Catalog\Models\Item::class, 'item_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'inventory_lot_id');
    }
}
