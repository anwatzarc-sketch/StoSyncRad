<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Models;

use App\Domains\Procurement\Enums\AsnStatus;
use App\Support\Eloquent\BaseModel;

/** Supplier advance shipment notice. */
final class AdvanceShipmentNotice extends BaseModel
{
    protected $table = 'advance_shipment_notices';

    protected $fillable = ['document_number', 'purchase_order_id', 'supplier_reference', 'expected_arrival', 'status', 'lines', 'created_by'];

    protected function casts(): array
    {
        return [
            'status' => AsnStatus::class,
            'lines' => 'array',
            'expected_arrival' => 'immutable_date',
        ];
    }
}
