<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Models;

use App\Support\Eloquent\BaseModel;

/** Append-only record of FIFO layer consumption by an outgoing line. */
final class ValuationLayerConsumption extends BaseModel
{
    protected $table = 'valuation_layer_consumptions';

    public $timestamps = false;

    protected $fillable = ['valuation_layer_id', 'stock_movement_line_id', 'quantity', 'unit_cost', 'created_at'];
}
