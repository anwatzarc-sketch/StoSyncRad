<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Models;

use App\Support\Eloquent\BaseModel;

/** Purchase price variance under standard costing. */
final class ValuationVariance extends BaseModel
{
    protected $table = 'valuation_variances';

    public $timestamps = false;

    protected $fillable = ['item_id', 'stock_movement_line_id', 'standard_unit_cost', 'actual_unit_cost', 'quantity', 'variance_value', 'created_at'];
}
