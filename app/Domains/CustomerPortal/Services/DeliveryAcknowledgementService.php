<?php

declare(strict_types=1);

namespace App\Domains\CustomerPortal\Services;

use App\Domains\CustomerPortal\Enums\AcknowledgementStatus as A;
use App\Domains\CustomerPortal\Enums\DeliveryIssueType;
use App\Domains\CustomerPortal\Enums\DeliveryOutcome as O;
use App\Domains\CustomerPortal\Models\DeliveryAcknowledgement;
use App\Domains\CustomerPortal\Models\DeliveryAcknowledgementLine;
use App\Domains\Fulfillment\Enums\SalesOrderStatus;
use App\Domains\Fulfillment\Enums\ShipmentStatus;
use App\Domains\Fulfillment\Models\SalesOrder;
use App\Domains\Fulfillment\Models\SalesOrderLine;
use App\Domains\Fulfillment\Models\Shipment;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Partners\Models\Partner;
use App\Support\Documents\ManagesDocuments;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Math\Decimal as D;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Delivery receipt (CP-1.1 section 5.7). The customer confirms what arrived and reports shortages,
 * damage or wrong items. This records the signed-in actor and the server time only: shipment status,
 * stock and the ledger never change here; discrepancies go to staff, who handle them through the
 * existing customer return, adjustment or support workflows.
 */
final class DeliveryAcknowledgementService
{
    use ManagesDocuments;

    private const ELIGIBLE_SHIPMENT = [ShipmentStatus::HANDED_OVER, ShipmentStatus::IN_TRANSIT, ShipmentStatus::DELIVERED];

    /**
     * @param  array<string, mixed>  $data  validated against DeliveryAcknowledgementSchema
     */
    public function submit(UserAccount $account, Partner $customer, string $salesOrderId, ?string $shipmentId, array $data): DeliveryAcknowledgement
    {
        $outcome = O::from((string) $data['outcome']);
        $issues = array_values((array) ($data['issues'] ?? []));
        if ($outcome === O::RECEIVED_IN_FULL && $issues !== []) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Choose "received with issues" to report a problem.', ['field' => 'outcome']);
        }
        if ($outcome === O::RECEIVED_WITH_ISSUES && $issues === []) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Describe at least one issue.', ['field' => 'issues']);
        }
        try {
            return DB::transaction(function () use ($account, $customer, $salesOrderId, $shipmentId, $data, $outcome, $issues) {
                $order = SalesOrder::query()->whereKey($salesOrderId)->where('customer_partner_id', $customer->id)->first()
                    ?? throw DomainException::notFound('order');
                if ($shipmentId !== null) {
                    $shipment = Shipment::query()->whereKey($shipmentId)->where('sales_order_id', $order->id)->lockForUpdate()->first()
                        ?? throw DomainException::notFound('shipment');
                    if (! in_array($shipment->status, self::ELIGIBLE_SHIPMENT, true)) {
                        throw DomainException::of(ErrorCode::INVALID_STATE_TRANSITION, 'This shipment has not been dispatched to you yet.', ['status' => $shipment->status->value]);
                    }
                } elseif (! in_array($order->status, [SalesOrderStatus::PARTIALLY_DELIVERED, SalesOrderStatus::DELIVERED], true)
                    || Shipment::query()->where('sales_order_id', $order->id)->exists()) {
                    throw DomainException::of(ErrorCode::INVALID_STATE_TRANSITION, 'Confirm receipt on the shipment instead.', ['status' => $order->status->value]);
                }
                $ack = DeliveryAcknowledgement::query()->create([
                    'customer_partner_id' => $customer->id, 'sales_order_id' => $order->id, 'shipment_id' => $shipmentId, 'account_id' => $account->id,
                    'outcome' => $outcome, 'status' => $outcome === O::RECEIVED_IN_FULL ? A::RECORDED : A::OPEN, 'note' => $data['note'] ?? null,
                ]);
                $lines = SalesOrderLine::query()->where('sales_order_id', $order->id)->get()->keyBy('id');
                foreach ($issues as $i => $issue) {
                    $line = $lines[$issue['sales_order_line_id'] ?? ''] ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Choose a product from this order.',
                        ['field' => "issues.$i.sales_order_line_id"]);
                    $quantity = isset($issue['quantity']) && $issue['quantity'] !== null && $issue['quantity'] !== '' ? (string) $issue['quantity'] : null;
                    if ($quantity !== null && (D::lte($quantity, '0') || D::gt($quantity, (string) $line->base_quantity))) {
                        throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'The quantity must be between zero and the ordered quantity.', ['field' => "issues.$i.quantity"]);
                    }
                    DeliveryAcknowledgementLine::query()->create(['acknowledgement_id' => $ack->id, 'sales_order_line_id' => $line->id, 'item_id' => $line->item_id,
                        'issue' => DeliveryIssueType::from((string) $issue['issue']), 'quantity' => $quantity, 'note' => $issue['note'] ?? null, 'created_at' => now()]);
                }
                $this->audit($account, 'portal.delivery.acknowledged', $ack, null, ['outcome' => $outcome->value, 'issues' => count($issues), 'order' => $order->document_number]);
                if ($ack->status === A::OPEN) {
                    $this->publish('portal.delivery.discrepancy_reported', $ack, ['document_number' => $order->document_number, 'customer_name' => $customer->name,
                        'outcome' => $outcome->value, 'warehouse_id' => $order->warehouse_id]);
                }

                return $ack;
            });
        } catch (UniqueConstraintViolationException) {
            throw DomainException::of(ErrorCode::DUPLICATE_RESOURCE, 'You already confirmed this delivery. Contact us to add information.');
        }
    }

    public function resolve(string $id, UserAccount $actor, ?string $comment, ?int $version = null): DeliveryAcknowledgement
    {
        if (trim((string) $comment) === '') {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Record how the issue was resolved.', ['field' => 'comment']);
        }

        return DB::transaction(function () use ($id, $actor, $comment, $version) {
            /** @var DeliveryAcknowledgement $ack */
            $ack = $this->lockDocument(DeliveryAcknowledgement::class, $id);
            $this->expectVersion($ack, $version);
            $this->transition($ack, A::RESOLVED, ['resolution_note' => $comment, 'resolved_by' => $actor->id, 'resolved_at' => now()]);
            $this->audit($actor, 'portal.delivery.resolved', $ack, null, null, $comment);

            return $ack;
        });
    }
}
