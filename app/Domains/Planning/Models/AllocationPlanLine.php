<?php

declare(strict_types=1);

namespace App\Domains\Planning\Models;

use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Allocation to one destination. */
final class AllocationPlanLine extends BaseModel
{
    protected $table = 'allocation_plan_lines';

    protected $fillable = ['allocation_plan_id', 'line_no', 'destination_warehouse_id', 'item_id', 'item_variant_id', 'suggested_quantity', 'quantity', 'cap_quantity', 'priority', 'transfer_order_id', 'notes'];

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'priority' => 'integer',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(AllocationPlan::class, 'allocation_plan_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Catalog\Models\Item::class, 'item_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Organization\Models\Warehouse::class, 'destination_warehouse_id');
    }
}
