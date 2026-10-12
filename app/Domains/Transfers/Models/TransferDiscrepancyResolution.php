<?php

declare(strict_types=1);

namespace App\Domains\Transfers\Models;

use App\Domains\Transfers\Enums\DiscrepancyResolution;
use App\Support\Eloquent\BaseModel;

/** Append-only authorized resolution of a discrepancy. */
final class TransferDiscrepancyResolution extends BaseModel
{
    protected $table = 'transfer_discrepancy_resolutions';

    protected $fillable = ['transfer_discrepancy_id', 'resolution', 'base_quantity', 'stock_movement_id', 'return_transfer_order_id', 'reason', 'resolved_by', 'resolved_at'];

    protected function casts(): array
    {
        return [
            'resolution' => DiscrepancyResolution::class,
            'resolved_at' => 'immutable_datetime',
        ];
    }
}
