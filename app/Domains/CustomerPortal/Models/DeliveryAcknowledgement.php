<?php

declare(strict_types=1);

namespace App\Domains\CustomerPortal\Models;

use App\Domains\CustomerPortal\Enums\AcknowledgementStatus;
use App\Domains\CustomerPortal\Enums\DeliveryOutcome;
use App\Domains\Fulfillment\Models\SalesOrder;
use App\Domains\Fulfillment\Models\Shipment;
use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A customer confirmation that a delivery arrived, with any discrepancies. It never changes stock or the shipment status. */
final class DeliveryAcknowledgement extends BaseModel
{
    protected $table = 'delivery_acknowledgements';

    protected $fillable = ['customer_partner_id', 'sales_order_id', 'shipment_id', 'account_id', 'outcome', 'status', 'note', 'resolution_note', 'resolved_by', 'resolved_at', 'version'];

    protected function casts(): array
    {
        return [
            'outcome' => DeliveryOutcome::class,
            'status' => AcknowledgementStatus::class,
            'resolved_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DeliveryAcknowledgementLine::class, 'acknowledgement_id')->orderBy('created_at');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'shipment_id');
    }
}
