<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Services;

use App\Domains\Fulfillment\Enums\FulfillmentPostOn;
use App\Domains\Fulfillment\Enums\SalesChannel;
use App\Domains\Fulfillment\Enums\SalesOrderStatus;
use App\Domains\Fulfillment\Enums\ShipmentStatus as S;
use App\Domains\Fulfillment\Models\SalesOrder;
use App\Domains\Fulfillment\Models\SalesOrderLine;
use App\Domains\Fulfillment\Models\Shipment;
use App\Domains\Fulfillment\Models\ShipmentPackage;
use App\Domains\Fulfillment\Models\ShipmentPackageLine;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Enums\ReservationReferenceType;
use App\Domains\Inventory\Enums\StockCondition;
use App\Domains\Inventory\Models\StockMovement;
use App\Domains\Inventory\Posting\InventoryPoster;
use App\Domains\Inventory\Posting\MovementLine;
use App\Domains\Inventory\Posting\MovementRequest;
use App\Domains\Inventory\Services\InventoryLocationResolver;
use App\Domains\Organization\Enums\DocumentType;
use App\Domains\Organization\Enums\InventoryLocationType;
use App\Domains\Organization\Enums\WarehouseCapability;
use App\Domains\Organization\Models\Warehouse;
use App\Domains\Partners\Enums\PartnerType;
use App\Domains\Partners\Models\Partner;
use App\Domains\Platform\Config\PlatformConfig;
use App\Support\Documents\LineQuantities;
use App\Support\Documents\ManagesDocuments;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Math\Decimal as D;
use App\Support\Transactions\PostingContext;
use App\Support\Transactions\PostingOutcome;
use App\Support\Transactions\PostingPipeline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Packing, carrier handover and delivery tracking (Chapter 08 §8.9).
 *
 * Packing pins the packed stock with reservations at the exact positions. The sale posts
 * (`location → customer`, SALE_DELIVERY) at handover when `fulfillment.sales.post_on = ship`,
 * otherwise when the delivered event arrives; until then the stock stays on hand and reserved.
 */
final class ShipmentWorkflow
{
    use ManagesDocuments;

    private const OPEN = [S::PACKED, S::HANDED_OVER, S::IN_TRANSIT, S::FAILED];

    public function __construct(
        private readonly LineQuantities $lines,
        private readonly PlatformConfig $config,
        private readonly PostingPipeline $pipeline,
        private readonly InventoryPoster $poster,
        private readonly InventoryLocationResolver $locations,
        private readonly PickedStock $picked,
        private readonly SalesOrderWorkflow $orders,
    ) {}

    /** @param  array<string, mixed>  $data  validated against PickedLinesSchema */
    public function pack(string $salesOrderId, UserAccount $actor, array $data): Shipment
    {
        return DB::transaction(function () use ($salesOrderId, $actor, $data) {
            $order = $this->lockDocument(SalesOrder::class, $salesOrderId);
            $this->authorizeWarehouse($actor, 'fulfillment.shipment.pack', $order->warehouse_id);
            $this->expectVersion($order, $data['version'] ?? null);
            $this->orders->assertChannelEnabled($order->channel);
            $this->assertStatus($order, [SalesOrderStatus::CONFIRMED, SalesOrderStatus::RESERVED, SalesOrderStatus::PICKING, SalesOrderStatus::PACKED, SalesOrderStatus::PARTIALLY_DELIVERED]);
            $warehouse = Warehouse::query()->findOrFail($order->warehouse_id);
            $warehouse->assertOperational();
            $warehouse->assertCapability(WarehouseCapability::PACK_AND_SHIP);
            if (! empty($data['carrier_partner_id']) && ! Partner::query()->findOrFail($data['carrier_partner_id'])->hasType(PartnerType::CARRIER)) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'The selected partner is not a carrier.', ['field' => 'carrier_partner_id']);
            }

            $orderLines = SalesOrderLine::query()->where('sales_order_id', $order->id)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $pending = $this->unpostedPackedQuantities($order->id);
            $shipment = new Shipment;
            $shipment->forceFill([
                'document_number' => $this->nextNumber(DocumentType::SHIPMENT, $order->warehouse_id), 'warehouse_id' => $order->warehouse_id,
                'sales_order_id' => $order->id, 'status' => S::PACKED, 'carrier_partner_id' => $data['carrier_partner_id'] ?? null,
                'tracking_number' => $data['tracking_number'] ?? null, 'packed_by' => $actor->id, 'packed_at' => now(),
            ])->save();
            $package = ShipmentPackage::query()->create([
                'shipment_id' => $shipment->id, 'package_no' => 1, 'weight' => $data['package_weight'] ?? null, 'tracking_number' => $data['tracking_number'] ?? null,
            ]);
            foreach (array_values($data['lines']) as $i => $picked) {
                $ol = $orderLines->get($picked['order_line_id']) ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Unknown order line.', ['field' => "lines.$i.order_line_id"]);
                $qty = D::exact($picked['quantity']);
                $outstanding = D::sub($ol->base_quantity, D::add($ol->delivered_base_quantity, $pending[$ol->id] ?? '0'));
                if (D::gt($qty, $outstanding)) {
                    throw DomainException::of(ErrorCode::QUANTITY_EXCEEDS_OUTSTANDING, "Line {$ol->line_no} exceeds the quantity still to ship.", ['field' => "lines.$i.quantity", 'outstanding' => D::trim($outstanding)]);
                }
                $pending[$ol->id] = D::add($pending[$ol->id] ?? '0', $qty);
                $item = $this->lines->item($ol->item_id, false);
                foreach ($this->picked->expand($item, $order->warehouse_id, $picked['storage_location_id'], $picked['inventory_lot_id'] ?? null,
                    $picked['serial_numbers'] ?? null, $qty, "lines.$i") as $row) {
                    $held = $this->picked->secure(ReservationReferenceType::SALES_ORDER, $order->id, $ol->id, $ol->item_id, $ol->item_variant_id,
                        $order->warehouse_id, $row, $actor, reserveUnreserved: true);
                    foreach ($held as $reservationId => $heldQty) {
                        ShipmentPackageLine::query()->create([
                            'shipment_package_id' => $package->id, 'sales_order_line_id' => $ol->id, 'item_id' => $ol->item_id, 'item_variant_id' => $ol->item_variant_id,
                            'storage_location_id' => $row['storage_location_id'], 'inventory_lot_id' => $row['lot_id'], 'item_serial_id' => $row['serial_id'],
                            'stock_reservation_id' => $reservationId, 'base_quantity' => $heldQty,
                        ]);
                    }
                }
            }
            $complete = $orderLines->every(fn ($l) => D::gte(D::add($l->delivered_base_quantity, $pending[$l->id] ?? '0'), $l->base_quantity));
            if (in_array($order->status, [SalesOrderStatus::CONFIRMED, SalesOrderStatus::RESERVED, SalesOrderStatus::PICKING], true)) {
                $target = $complete ? SalesOrderStatus::PACKED : SalesOrderStatus::PICKING;
                if ($target !== $order->status) {
                    $this->transition($order, $target);
                }
            }
            $this->event($shipment, S::PACKED, 'system', null);
            $this->audit($actor, 'fulfillment.shipment.packed', $shipment, null, ['document_number' => $shipment->document_number, 'sales_order' => $order->document_number]);
            $this->publish('fulfillment.shipment.packed', $shipment, ['document_number' => $shipment->document_number, 'sales_order_id' => $order->id]);

            return $shipment->refresh();
        });
    }

    /** @param  array<string, mixed>  $data  carrier_partner_id, tracking_number, version */
    public function handover(string $id, UserAccount $actor, ?string $idempotencyKey, array $data = []): PostingOutcome
    {
        return $this->pipeline->run($actor, 'fulfillment.shipment.handover', $idempotencyKey, ['shipment_id' => $id] + $data,
            function (PostingContext $ctx) use ($id, $actor, $data) {
                $shipment = $this->lockDocument(Shipment::class, $id);
                $this->authorizeWarehouse($actor, 'fulfillment.shipment.handover', $shipment->warehouse_id);
                $this->assertOrderChannel($shipment);
                $this->expectVersion($shipment, $data['version'] ?? null);
                $shipment->forceFill(array_filter(['carrier_partner_id' => $data['carrier_partner_id'] ?? null, 'tracking_number' => $data['tracking_number'] ?? null]));
                $this->transition($shipment, S::HANDED_OVER, ['handed_over_by' => $actor->id, 'handed_over_at' => now()]);
                $this->event($shipment, S::HANDED_OVER, 'user', null);
                $movement = null;
                if ($this->postOn($shipment) === FulfillmentPostOn::SHIP) {
                    $movement = $this->postSale($shipment, $ctx, $actor);
                }
                $ctx->audit('fulfillment.shipment.handed_over', 'shipments', $shipment->id, null, ['tracking_number' => $shipment->tracking_number, 'movement_number' => $movement?->movement_number]);
                $ctx->publish('fulfillment.shipment.handed_over', 'shipments', $shipment->id, ['document_number' => $shipment->document_number,
                    'sales_order_id' => $shipment->sales_order_id, 'tracking_number' => $shipment->tracking_number]);

                return $this->outcome($shipment, $movement);
            }, failureAudit: ['fulfillment.shipment.handover_failed', 'shipments', $id]);
    }

    /**
     * Carrier or manual status update. Delivery confirmation posts the sale when configured to.
     *
     * @param  array<string, mixed>  $data  validated against ShipmentEventSchema
     */
    public function recordEvent(string $id, ?UserAccount $actor, ?string $idempotencyKey, array $data, string $source = 'user'): PostingOutcome
    {
        return $this->pipeline->run($actor, 'fulfillment.shipment.event', $idempotencyKey, ['shipment_id' => $id] + $data,
            function (PostingContext $ctx) use ($id, $actor, $data, $source) {
                $shipment = $this->lockDocument(Shipment::class, $id);
                $this->authorizeWarehouse($actor, 'fulfillment.shipment.update_status', $shipment->warehouse_id);
                $this->assertOrderChannel($shipment);
                $status = S::from($data['status']);
                if ($status === S::PACKED || $status === S::HANDED_OVER) {
                    throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Packing and handover are separate actions.', ['field' => 'status']);
                }
                $this->transition($shipment, $status, array_filter([
                    'delivered_at' => $status === S::DELIVERED ? ($data['occurred_at'] ?? now()) : null,
                    'tracking_number' => $data['tracking_number'] ?? null,
                ]));
                $this->event($shipment, $status, $source, $data['payload'] ?? null, $data['occurred_at'] ?? null);
                $movement = null;
                if ($status === S::DELIVERED && $shipment->stock_movement_id === null) {
                    $movement = $this->postSale($shipment, $ctx, $actor);
                }
                if ($status === S::RETURNED && $shipment->stock_movement_id === null) {
                    // Never left our books: the reservations stay with the order for re-shipping.
                    $ctx->publish('fulfillment.shipment.returned_unposted', 'shipments', $shipment->id, ['sales_order_id' => $shipment->sales_order_id]);
                }
                $ctx->audit('fulfillment.shipment.status_changed', 'shipments', $shipment->id, null, ['status' => $status->value, 'source' => $source]);
                $ctx->publish('fulfillment.shipment.'.$status->value, 'shipments', $shipment->id, ['document_number' => $shipment->document_number,
                    'sales_order_id' => $shipment->sales_order_id, 'tracking_number' => $shipment->tracking_number]);

                return $this->outcome($shipment, $movement);
            }, failureAudit: ['fulfillment.shipment.event_failed', 'shipments', $id]);
    }

    /** Unpack a shipment that has not left; its reservations stay with the order. */
    public function cancel(string $id, UserAccount $actor, ?string $reason, ?int $version = null): Shipment
    {
        return DB::transaction(function () use ($id, $actor, $reason, $version) {
            $shipment = $this->lockDocument(Shipment::class, $id);
            $this->assertOrderChannel($shipment);
            $this->authorizeWarehouse($actor, 'fulfillment.shipment.pack', $shipment->warehouse_id);
            $this->expectVersion($shipment, $version);
            $this->transition($shipment, S::CANCELLED);
            $this->event($shipment, S::CANCELLED, 'user', $reason === null ? null : ['reason' => $reason]);
            $this->audit($actor, 'fulfillment.shipment.cancelled', $shipment, null, null, $reason);

            return $shipment;
        });
    }

    private function postSale(Shipment $shipment, PostingContext $ctx, ?UserAccount $actor): StockMovement
    {
        $order = $this->lockDocument(SalesOrder::class, $shipment->sales_order_id);
        $customer = $this->locations->virtual(InventoryLocationType::CUSTOMER)->id;
        $packageLines = ShipmentPackageLine::query()->whereIn('shipment_package_id', ShipmentPackage::query()->where('shipment_id', $shipment->id)->select('id'))
            ->orderBy('id')->get();
        $movementLines = [];
        $delivered = [];
        foreach ($packageLines as $pl) {
            $movementLines[] = new MovementLine($pl->item_id, $pl->item_variant_id, $this->picked->location($pl->storage_location_id), StockCondition::AVAILABLE,
                $customer, StockCondition::AVAILABLE, D::exact($pl->base_quantity), lotId: $pl->inventory_lot_id, serialId: $pl->item_serial_id,
                sourceLineType: 'shipment_package_line', sourceLineId: $pl->id,
                consumeReservations: $pl->stock_reservation_id ? [$pl->stock_reservation_id => D::exact($pl->base_quantity)] : []);
            $delivered[$pl->sales_order_line_id] = D::add($delivered[$pl->sales_order_line_id] ?? '0', $pl->base_quantity);
        }
        $movement = $this->poster->post(new MovementRequest(MovementType::SALE_DELIVERY, DocumentType::SHIPMENT, $shipment->id, $shipment->document_number,
            (string) Str::uuid7(), $movementLines, warehouseId: $shipment->warehouse_id, partnerId: $order->customer_partner_id), $ctx);
        $shipment->forceFill(['stock_movement_id' => $movement->id])->save();
        $this->orders->recordDelivered($order, $delivered, $actor);

        return $movement;
    }

    /** @return array<string, string> order line id → packed base qty on shipments not yet posted */
    private function unpostedPackedQuantities(string $orderId): array
    {
        return DB::table('shipment_package_lines as l')->join('shipment_packages as p', 'p.id', '=', 'l.shipment_package_id')
            ->join('shipments as s', 's.id', '=', 'p.shipment_id')->where('s.sales_order_id', $orderId)->whereNull('s.stock_movement_id')
            ->whereIn('s.status', array_map(fn (S $s) => $s->value, self::OPEN))
            ->groupBy('l.sales_order_line_id')->selectRaw('l.sales_order_line_id, SUM(l.base_quantity) AS q')->pluck('q', 'sales_order_line_id')
            ->map(fn ($q) => D::exact((string) $q))->all();
    }

    private function postOn(Shipment $shipment): FulfillmentPostOn
    {
        return FulfillmentPostOn::from((string) $this->config->get('fulfillment.sales.post_on', ['warehouse' => $shipment->warehouse_id]));
    }

    /** @param  array<string, mixed>|null  $payload */
    private function event(Shipment $shipment, S $status, string $source, ?array $payload, mixed $occurredAt = null): void
    {
        DB::table('shipment_events')->insert([
            'id' => (string) Str::uuid7(), 'shipment_id' => $shipment->id, 'status' => $status->value, 'source' => $source,
            'payload' => $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR), 'occurred_at' => $occurredAt ?? now(), 'created_at' => now(),
        ]);
    }

    private function outcome(Shipment $shipment, ?StockMovement $movement): PostingOutcome
    {
        return new PostingOutcome(['shipment_id' => $shipment->id, 'document_number' => $shipment->document_number, 'status' => $shipment->status->value,
            'stock_movement_id' => $movement?->id ?? $shipment->stock_movement_id, 'movement_number' => $movement?->movement_number], 200, DocumentType::SHIPMENT->value, $shipment->id);
    }

    /** Shipment actions follow the feature flag of the order's channel (e-commerce, retail, customer portal). */
    private function assertOrderChannel(Shipment $shipment): void
    {
        $channel = SalesOrder::query()->whereKey($shipment->sales_order_id)->value('channel');
        if ($channel !== null) {
            $this->orders->assertChannelEnabled($channel instanceof SalesChannel ? $channel : SalesChannel::from((string) $channel));
        }
    }
}
