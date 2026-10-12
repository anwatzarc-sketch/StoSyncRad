<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Models;

use App\Support\Eloquent\BaseModel;

/** Append-only snapshot taken when an approved PO is revised. */
final class PurchaseOrderRevision extends BaseModel
{
    protected $table = 'purchase_order_revisions';

    public $timestamps = false;

    protected $fillable = ['purchase_order_id', 'revision', 'snapshot', 'reason', 'created_by', 'created_at'];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'revision' => 'integer',
        ];
    }
}
