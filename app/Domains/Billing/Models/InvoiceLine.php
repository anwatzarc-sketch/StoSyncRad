<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Support\Eloquent\BaseModel;

/** An invoice line. */
final class InvoiceLine extends BaseModel
{
    protected $table = 'invoice_lines';

    protected $fillable = ['invoice_id', 'line_no', 'item_id', 'sales_order_line_id', 'description', 'quantity', 'unit_price', 'line_total', 'tax_rate', 'tax_amount'];

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
        ];
    }
}
