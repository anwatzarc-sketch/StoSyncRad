<?php

declare(strict_types=1);

namespace App\Domains\CustomerPortal\Models;

use App\Domains\CustomerPortal\Enums\DeliveryIssueType;
use App\Support\Eloquent\BaseModel;

/** One reported discrepancy on a delivery. */
final class DeliveryAcknowledgementLine extends BaseModel
{
    protected $table = 'delivery_acknowledgement_lines';

    protected $fillable = ['acknowledgement_id', 'sales_order_line_id', 'item_id', 'issue', 'quantity', 'note', 'created_at'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'issue' => DeliveryIssueType::class,
            'created_at' => 'immutable_datetime',
        ];
    }
}
