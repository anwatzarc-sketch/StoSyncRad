<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Models;

use App\Support\Eloquent\BaseModel;

/** Moving-average cost projection per item (company or warehouse level). */
final class ItemCostProjection extends BaseModel
{
    protected $table = 'item_cost_projections';

    protected $fillable = ['item_id', 'item_variant_id', 'warehouse_id', 'quantity', 'total_value', 'average_cost', 'version'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
        ];
    }
}
