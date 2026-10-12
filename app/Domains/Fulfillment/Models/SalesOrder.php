<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Models;

use App\Domains\Fulfillment\Enums\SalesChannel;
use App\Domains\Fulfillment\Enums\SalesOrderStatus;
use App\Domains\Organization\Enums\DocumentType;
use App\Domains\Organization\Models\Warehouse;
use App\Domains\Partners\Models\Partner;
use App\Support\Eloquent\BaseModel;
use App\Support\Enums\Priority;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Stock-side sales order — retail counter or e-commerce (Chapter 08 §8.8–8.9). */
final class SalesOrder extends BaseModel
{
    protected $table = 'sales_orders';

    protected $fillable = ['document_number', 'warehouse_id', 'branch_id', 'document_date', 'reference', 'notes', 'priority', 'created_by', 'updated_by', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'posted_by', 'posted_at', 'cancelled_by', 'cancelled_at', 'cancellation_reason_lookup_value_id', 'cancellation_reason', 'version', 'status', 'channel', 'customer_partner_id', 'external_source', 'external_order_id', 'shipping_address', 'currency', 'total', 'payment_reference', 'confirmed_by', 'confirmed_at', 'credit_override_by', 'credit_override_reason'];

    protected function casts(): array
    {
        return [
            'document_date' => 'immutable_date',
            'submitted_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'posted_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'version' => 'integer',
            'priority' => Priority::class,
            'status' => SalesOrderStatus::class,
            'channel' => SalesChannel::class,
            'shipping_address' => 'array',
            'confirmed_at' => 'immutable_datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesOrderLine::class, 'sales_order_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'customer_partner_id');
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class, 'sales_order_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    /** Document type key used by approvals, SoD, numbering and attachments. */
    public static function documentType(): DocumentType
    {
        return DocumentType::SALES_ORDER;
    }
}
