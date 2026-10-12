<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Models;

use App\Domains\Inventory\Enums\ValuationLayerStatus;
use App\Support\Eloquent\BaseModel;

/** FIFO valuation layer created by an incoming movement. */
final class ValuationLayer extends BaseModel
{
    protected $table = 'valuation_layers';

    protected $fillable = ['item_id', 'item_variant_id', 'warehouse_id', 'stock_movement_line_id', 'original_quantity', 'remaining_quantity', 'unit_cost', 'status', 'layer_date'];

    protected function casts(): array
    {
        return [
            'status' => ValuationLayerStatus::class,
            'layer_date' => 'immutable_datetime',
        ];
    }
}
