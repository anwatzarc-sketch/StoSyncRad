<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use App\Domains\Catalog\Enums\TrackingMode;
use App\Domains\Catalog\Models\Item;
use App\Domains\Fulfillment\Enums\CustomerReturnStatus;
use App\Domains\Fulfillment\Enums\InternalReturnStatus;
use App\Domains\Fulfillment\Enums\PickSourceType;
use App\Domains\Fulfillment\Enums\SalesOrderStatus;
use App\Domains\Fulfillment\Enums\ShipmentStatus;
use App\Domains\Fulfillment\Enums\StockRequestStatus;
use App\Domains\Fulfillment\Models\PickListLine;
use App\Domains\Fulfillment\Models\SalesOrder;
use App\Domains\Fulfillment\Models\StockIssueLine;
use App\Domains\Fulfillment\Services\CustomerReturnWorkflow;
use App\Domains\Fulfillment\Services\InternalReturnWorkflow;
use App\Domains\Fulfillment\Services\PickListService;
use App\Domains\Fulfillment\Services\SalesOrderWorkflow;
use App\Domains\Fulfillment\Services\ShipmentWorkflow;
use App\Domains\Fulfillment\Services\StockIssueWorkflow;
use App\Domains\Fulfillment\Services\StockRequestWorkflow;
use App\Domains\Inventory\Enums\SerialStatus;
use App\Domains\Inventory\Enums\StockCondition;
use App\Domains\Inventory\Models\ItemSerial;
use App\Domains\Inventory\Services\LotService;
use App\Domains\Organization\Enums\BranchKind;
use App\Domains\Organization\Enums\StorageLocationUsage;
use App\Domains\Organization\Models\Warehouse;
use App\Domains\Partners\Models\Partner;
use App\Domains\Platform\Seeds\DocumentSequencesSeed;
use App\Domains\Platform\Seeds\SeedRunner;
use App\Domains\Platform\Seeds\SodRulesSeed;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsActors;
use Tests\Support\BuildsInventory;
use Tests\TestCase;

/**
 * Chapter 08 §8.6–8.9 / §8.15 — request→reservation→issue→return chain, counter sale,
 * e-commerce ship/deliver paths and customer return restock.
 */
final class FulfillmentWorkflowTest extends TestCase
{
    use BuildsActors;
    use BuildsInventory;

    protected function setUp(): void
    {
        parent::setUp();
        app(SeedRunner::class)->run(DocumentSequencesSeed::class);
        app(SeedRunner::class)->run(SodRulesSeed::class);
    }

    private function key(): string
    {
        return (string) Str::uuid7();
    }

    private function partner(string $type): Partner
    {
        return Partner::query()->forceCreate(['code' => strtoupper($type).'-'.Str::upper(Str::random(5)), 'name' => ucfirst($type).' '.Str::random(4), 'partner_types' => [$type]]);
    }

    private function site(BranchKind $kind): Warehouse
    {
        return $this->warehouse('W'.Str::upper(Str::random(5)), $this->branch('B'.Str::upper(Str::random(4)), $kind));
    }

    private function assertDomainError(ErrorCode $code, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Expected {$code->value}");
        } catch (DomainException $e) {
            $this->assertSame($code, $e->errorCode, $e->getMessage());
        }
    }

    private function reservedFor(string $referenceId): string
    {
        return (string) DB::table('stock_reservations')->where('reference_id', $referenceId)
            ->selectRaw('COALESCE(SUM(quantity_reserved - quantity_consumed - quantity_released), 0) AS q')->value('q');
    }

    public function test_request_reserves_on_approval_issue_consumes_and_internal_return_restocks_pending_inspection(): void
    {
        $wh = $this->site(BranchKind::HEADQUARTERS);
        $item = $this->item();
        $this->receive($item, $wh, '50', '4');
        $requester = $this->superAdmin();
        $approver = $this->superAdmin();
        $requests = app(StockRequestWorkflow::class);

        $req = $requests->save(['warehouse_id' => $wh->id, 'purpose' => 'Workshop', 'lines' => [['item_id' => $item->id, 'quantity' => '20']]], $requester);
        $requests->submit($req->id, $requester);
        $this->assertDomainError(ErrorCode::SOD_VIOLATION, fn () => $requests->approve($req->id, $requester));
        $line = $req->lines()->first();
        $requests->approve($req->id, $approver, [['line_id' => $line->id, 'approved_quantity' => '15']]);
        $this->assertSame('15.000000', $this->reservedFor($req->id), 'approval reserves the approved quantity');

        $issues = app(StockIssueWorkflow::class);
        $storekeeper = $this->superAdmin();
        $issue = $issues->save(['stock_request_id' => $req->id, 'lines' => [[
            'stock_request_line_id' => $line->id, 'item_id' => $item->id, 'storage_location_id' => $wh->default_storage_location_id, 'quantity' => '16',
        ]]], $storekeeper);
        $this->assertDomainError(ErrorCode::QUANTITY_EXCEEDS_OUTSTANDING, fn () => $issues->post($issue->id, $storekeeper, $this->key()));

        $issue = $issues->save(['version' => $issue->version, 'lines' => [[
            'stock_request_line_id' => $line->id, 'item_id' => $item->id, 'storage_location_id' => $wh->default_storage_location_id, 'quantity' => '10',
        ]]], $storekeeper, $issue->id);
        $outcome = $issues->post($issue->id, $storekeeper, $this->key());
        $this->assertSame('posted', $outcome->body['status']);
        $this->assertSame('40.000000', $this->onHand($item, $wh));
        $this->assertSame('5.000000', $this->reservedFor($req->id), 'issue consumed 10 of the 15 reserved');
        $this->assertSame(StockRequestStatus::PARTIALLY_FULFILLED, $req->refresh()->status);
        $this->assertSame('4.00000000', (string) StockIssueLine::query()->where('stock_issue_id', $issue->id)->value('unit_cost_snapshot'));

        // Remaining 5 → fulfilled; leftover reservations released.
        $second = $issues->save(['stock_request_id' => $req->id, 'lines' => [[
            'stock_request_line_id' => $line->id, 'item_id' => $item->id, 'storage_location_id' => $wh->default_storage_location_id, 'quantity' => '5',
        ]]], $storekeeper);
        $issues->post($second->id, $storekeeper, $this->key());
        $this->assertSame(StockRequestStatus::FULFILLED, $req->refresh()->status);
        $this->assertSame('0', $this->reservedFor($req->id) === '0.000000' ? '0' : $this->reservedFor($req->id));
        $this->assertSame('35.000000', $this->onHand($item, $wh));

        // Internal return of 3 units: back in the returns location, pending inspection, at the issue cost.
        $returns = app(InternalReturnWorkflow::class);
        $issueLine = StockIssueLine::query()->where('stock_issue_id', $issue->id)->firstOrFail();
        $ret = $returns->create($issue->id, $storekeeper, ['reason' => 'Unused', 'lines' => [['source_line_id' => $issueLine->id, 'quantity' => '3']]]);
        $this->assertDomainError(ErrorCode::QUANTITY_EXCEEDS_OUTSTANDING, fn () => $returns->create($issue->id, $storekeeper,
            ['lines' => [['source_line_id' => $issueLine->id, 'quantity' => '8']]]));
        $returns->approve($ret->id, $approver);
        $returns->post($ret->id, $approver, $this->key());
        $this->assertSame(InternalReturnStatus::POSTED, $ret->refresh()->status);
        $this->assertSame('3.000000', $this->onHand($item, $wh, StockCondition::RETURNED_PENDING_INSPECTION));
        $this->assertSame('3.000000', (string) DB::table('inventory_balances')->where('item_id', $item->id)
            ->where('inventory_location_id', $this->stockLocationId($wh, StorageLocationUsage::RETURNS))->sum('quantity_on_hand'));
        $this->assertSame('3.000000', (string) $issueLine->refresh()->returned_base_quantity);
        $this->assertSame('3.000000', (string) DB::table('stock_request_lines')->where('id', $line->id)->value('returned_base_quantity'));
    }

    public function test_partial_fulfillment_can_be_forbidden_per_warehouse(): void
    {
        $wh = $this->site(BranchKind::HEADQUARTERS);
        $item = $this->item();
        $this->receive($item, $wh, '50');
        $this->setConfig('fulfillment.request.partial_fulfillment', 'forbid');
        $requests = app(StockRequestWorkflow::class);
        $requester = $this->superAdmin();
        $req = $requests->save(['warehouse_id' => $wh->id, 'lines' => [['item_id' => $item->id, 'quantity' => '10']]], $requester);
        $requests->submit($req->id, $requester);
        $requests->approve($req->id, $this->superAdmin());
        $issues = app(StockIssueWorkflow::class);
        $issue = $issues->save(['stock_request_id' => $req->id, 'lines' => [['stock_request_line_id' => $req->lines()->first()->id, 'item_id' => $item->id,
            'storage_location_id' => $wh->default_storage_location_id, 'quantity' => '6']]], $requester);
        $this->assertDomainError(ErrorCode::POLICY_VIOLATION, fn () => $issues->post($issue->id, $requester, $this->key()));
        $this->assertSame('50.000000', $this->onHand($item, $wh), 'a rejected posting leaves no trace');
    }

    public function test_counter_sale_is_feature_gated_reserves_on_confirm_and_posts_the_delivery(): void
    {
        $store = $this->site(BranchKind::RETAIL_STORE);
        $item = $this->item();
        $this->receive($item, $store, '12', '7');
        $cashier = $this->superAdmin();
        $orders = app(SalesOrderWorkflow::class);
        $payload = ['warehouse_id' => $store->id, 'channel' => 'retail_counter', 'lines' => [['sku' => $item->sku, 'quantity' => '2', 'unit_price' => '15.50']]];

        $this->assertDomainError(ErrorCode::FEATURE_DISABLED, fn () => $orders->save($payload, $cashier));
        $this->setConfig('feature.retail_sales', true);
        $this->assertDomainError(ErrorCode::VALIDATION_FAILED, fn () => $orders->save($payload, $cashier)); // no walk-in customer configured
        $this->setConfig('fulfillment.sales.walk_in_customer_partner_id', $this->partner('customer')->id);

        $order = $orders->save($payload, $cashier);
        $this->assertSame('31.0000', (string) $order->total);
        $orders->confirm($order->id, $cashier);
        $this->assertSame(SalesOrderStatus::RESERVED, $order->refresh()->status);
        $this->assertSame('2.000000', $this->reservedFor($order->id));

        $line = $order->lines()->first();
        $this->assertDomainError(ErrorCode::QUANTITY_EXCEEDS_OUTSTANDING, fn () => $orders->deliver($order->id, $cashier, $this->key(),
            ['lines' => [['order_line_id' => $line->id, 'storage_location_id' => $store->default_storage_location_id, 'quantity' => '3']]]));
        $outcome = $orders->deliver($order->id, $cashier, $this->key(),
            ['lines' => [['order_line_id' => $line->id, 'storage_location_id' => $store->default_storage_location_id, 'quantity' => '2']]]);
        $this->assertSame(SalesOrderStatus::DELIVERED->value, $outcome->body['status']);
        $this->assertSame('10.000000', $this->onHand($item, $store));
        $this->assertSame('0.000000', $this->reservedFor($order->id));
        $this->assertSame('sale_delivery', DB::table('stock_movements')->where('id', $outcome->body['stock_movement_id'])->value('movement_type'));
    }

    public function test_ecommerce_intake_dedupes_external_ids_and_blocks_backorders(): void
    {
        $hub = $this->site(BranchKind::ECOMMERCE_FULFILLMENT_HUB);
        $item = $this->item();
        $this->receive($item, $hub, '3');
        $this->setConfig('feature.ecommerce_fulfillment', true);
        $customer = $this->partner('customer');
        $api = $this->superAdmin();
        $orders = app(SalesOrderWorkflow::class);
        $payload = ['warehouse_id' => $hub->id, 'channel' => 'ecommerce', 'customer_partner_id' => $customer->id, 'external_source' => 'shopify',
            'external_order_id' => 'SO-'.Str::random(6), 'lines' => [['sku' => $item->sku, 'quantity' => '5']]];

        $order = $orders->save($payload, $api);
        $this->assertDomainError(ErrorCode::DUPLICATE_RESOURCE, fn () => $orders->save($payload, $api));
        $this->assertDomainError(ErrorCode::INSUFFICIENT_STOCK, fn () => $orders->confirm($order->id, $api));
        $this->assertSame(SalesOrderStatus::DRAFT, $order->refresh()->status, 'a blocked confirmation rolls back completely');
        $this->assertSame('0', (string) DB::table('stock_reservations')->where('reference_id', $order->id)->count());
    }

    public function test_ecommerce_pick_pack_and_handover_posts_on_ship(): void
    {
        [$hub, $item, $order, $packer] = $this->ecommerceOrder('4');
        $picks = app(PickListService::class);
        $list = $picks->generate(PickSourceType::SALES_ORDER, $order->id, $packer);
        $this->assertSame(SalesOrderStatus::PICKING, $order->refresh()->status);
        $pl = PickListLine::query()->where('pick_list_id', $list->id)->firstOrFail();
        $this->assertSame($hub->default_storage_location_id, $pl->storage_location_id);
        $this->assertNotNull($pl->stock_reservation_id, 'pick suggestions follow the reservation');
        $picks->recordPicks($list->id, $packer, [['line_id' => $pl->id, 'picked_quantity' => '4']]);
        $picks->complete($list->id, $packer);
        $payload = $picks->pickedPayload($list->id);

        $shipments = app(ShipmentWorkflow::class);
        $shipment = $shipments->pack($order->id, $packer, ['lines' => $payload, 'carrier_partner_id' => $this->partner('carrier')->id, 'package_weight' => '1.2']);
        $this->assertSame(SalesOrderStatus::PACKED, $order->refresh()->status);
        $this->assertSame('10.000000', $this->onHand($item, $hub), 'packing does not move stock');
        $this->assertSame('4.000000', $this->reservedFor($order->id));
        $this->assertDomainError(ErrorCode::QUANTITY_EXCEEDS_OUTSTANDING, fn () => $shipments->pack($order->id, $packer, ['lines' => $payload]));

        $outcome = $shipments->handover($shipment->id, $packer, $this->key(), ['tracking_number' => 'TRK-1']);
        $this->assertNotNull($outcome->body['stock_movement_id']);
        $this->assertSame('6.000000', $this->onHand($item, $hub));
        $this->assertSame(SalesOrderStatus::DELIVERED, $order->refresh()->status);
        $shipments->recordEvent($shipment->id, $packer, $this->key(), ['status' => 'in_transit']);
        $shipments->recordEvent($shipment->id, $packer, $this->key(), ['status' => 'delivered']);
        $this->assertSame(ShipmentStatus::DELIVERED, $shipment->refresh()->status);
        $this->assertSame(1, DB::table('stock_movements')->where('source_document_id', $shipment->id)->count(), 'delivery after posting does not post twice');
        $this->assertSame(4, DB::table('shipment_events')->where('shipment_id', $shipment->id)->count());
    }

    public function test_post_on_delivery_confirmation_keeps_stock_reserved_until_the_carrier_confirms(): void
    {
        [$hub, $item, $order, $packer] = $this->ecommerceOrder('4');
        $this->setConfig('fulfillment.sales.post_on', 'delivery_confirmation');
        $shipments = app(ShipmentWorkflow::class);
        $shipment = $shipments->pack($order->id, $packer, ['lines' => [['order_line_id' => $order->lines()->first()->id,
            'storage_location_id' => $hub->default_storage_location_id, 'quantity' => '4']]]);
        $outcome = $shipments->handover($shipment->id, $packer, $this->key());
        $this->assertNull($outcome->body['stock_movement_id']);
        $this->assertSame('10.000000', $this->onHand($item, $hub));
        $this->assertSame('4.000000', $this->reservedFor($order->id));

        $shipments->recordEvent($shipment->id, null, $this->key(), ['status' => 'delivered', 'payload' => ['carrier_event' => 'DLV']], 'carrier');
        $this->assertSame('6.000000', $this->onHand($item, $hub));
        $this->assertSame(SalesOrderStatus::DELIVERED, $order->refresh()->status);
        $this->assertNotNull($shipment->refresh()->stock_movement_id);
    }

    public function test_customer_return_needs_an_rma_and_restocks_in_the_inspected_condition(): void
    {
        $store = $this->site(BranchKind::RETAIL_STORE);
        $item = $this->item(TrackingMode::SERIAL);
        $serials = [];
        foreach (['SN-A1', 'SN-A2'] as $sn) {
            $serials[$sn] = app(LotService::class)->resolveSerial($item, null, $sn.Str::random(4), null);
            $this->receive($item, $store, '1', '100', serialId: $serials[$sn]->id);
        }
        $this->setConfig('feature.retail_sales', true);
        $cashier = $this->superAdmin();
        $orders = app(SalesOrderWorkflow::class);
        $order = $orders->save(['warehouse_id' => $store->id, 'customer_partner_id' => $this->partner('customer')->id,
            'lines' => [['item_id' => $item->id, 'quantity' => '2']]], $cashier);
        $orders->confirm($order->id, $cashier);
        $line = $order->lines()->first();
        $this->assertDomainError(ErrorCode::TRACKING_MODE_REQUIRES_SERIAL, fn () => $orders->deliver($order->id, $cashier, $this->key(),
            ['lines' => [['order_line_id' => $line->id, 'storage_location_id' => $store->default_storage_location_id, 'quantity' => '2']]]));
        $orders->deliver($order->id, $cashier, $this->key(), ['lines' => [['order_line_id' => $line->id, 'storage_location_id' => $store->default_storage_location_id,
            'quantity' => '2', 'serial_numbers' => [$serials['SN-A1']->serial_number, $serials['SN-A2']->serial_number]]]]);
        $this->assertSame(SerialStatus::SOLD, $serials['SN-A1']->refresh()->lifecycle_status);

        $returns = app(CustomerReturnWorkflow::class);
        $agent = $this->superAdmin();
        $rma = $returns->request($order->id, $agent, ['reason' => 'Faulty', 'refund_reference' => 'RF-1',
            'lines' => [['source_line_id' => $line->id, 'quantity' => '1', 'serial_number' => $serials['SN-A1']->serial_number]]]);
        $this->assertSame(CustomerReturnStatus::REQUESTED, $rma->status);
        $this->assertDomainError(ErrorCode::INVALID_STATE_TRANSITION, fn () => $returns->receive($rma->id, $agent));
        $returns->authorize($rma->id, $agent);
        $returns->receive($rma->id, $agent);
        $returns->inspect($rma->id, $agent, [['line_id' => $rma->lines()->first()->id, 'resulting_condition' => 'damaged']]);
        $outcome = $returns->post($rma->id, $agent, $this->key());

        $this->assertSame('posted', $outcome->body['status']);
        $this->assertSame('1.000000', $this->onHand($item, $store, StockCondition::DAMAGED));
        $serial = ItemSerial::query()->findOrFail($serials['SN-A1']->id);
        $this->assertSame(SerialStatus::IN_STOCK, $serial->lifecycle_status);
        $this->assertSame(StockCondition::DAMAGED, $serial->current_condition);
        $this->assertSame('100.00000000', (string) DB::table('stock_movement_lines')->where('stock_movement_id', $outcome->body['stock_movement_id'])->value('unit_cost_snapshot'),
            'returns are valued at the original sale cost');
        $this->assertSame('1.000000', (string) DB::table('sales_order_lines')->where('id', $line->id)->value('returned_base_quantity'));
        $this->assertDomainError(ErrorCode::SERIAL_STATE_INVALID, fn () => $returns->request($order->id, $agent,
            ['lines' => [['source_line_id' => $line->id, 'quantity' => '1', 'serial_number' => $serials['SN-A1']->serial_number]]]));
    }

    public function test_cancelling_an_order_releases_its_reservations_and_unshipped_shipments(): void
    {
        [$hub, $item, $order, $packer] = $this->ecommerceOrder('3');
        $shipments = app(ShipmentWorkflow::class);
        $shipment = $shipments->pack($order->id, $packer, ['lines' => [['order_line_id' => $order->lines()->first()->id,
            'storage_location_id' => $hub->default_storage_location_id, 'quantity' => '3']]]);
        app(SalesOrderWorkflow::class)->cancel($order->id, $packer, 'Customer cancelled');
        $this->assertSame(SalesOrderStatus::CANCELLED, $order->refresh()->status);
        $this->assertSame(ShipmentStatus::CANCELLED, $shipment->refresh()->status);
        $this->assertSame('0.000000', $this->reservedFor($order->id));
        $this->assertSame('0.000000', (string) DB::table('inventory_balances')->where('item_id', $item->id)->sum('quantity_reserved'));
    }

    /** @return array{0: Warehouse, 1: Item, 2: SalesOrder, 3: \App\Domains\IdentityAccess\Models\UserAccount} */
    private function ecommerceOrder(string $qty): array
    {
        $hub = $this->site(BranchKind::ECOMMERCE_FULFILLMENT_HUB);
        $item = $this->item();
        $this->receive($item, $hub, '10', '5');
        $this->setConfig('feature.ecommerce_fulfillment', true);
        $actor = $this->superAdmin();
        $orders = app(SalesOrderWorkflow::class);
        $order = $orders->save(['warehouse_id' => $hub->id, 'channel' => 'ecommerce', 'customer_partner_id' => $this->partner('customer')->id,
            'external_source' => 'web', 'external_order_id' => 'WEB-'.Str::random(8), 'shipping_address' => ['city' => 'Nairobi'],
            'lines' => [['item_id' => $item->id, 'quantity' => $qty]]], $actor);
        $orders->confirm($order->id, $actor);

        return [$hub, $item, $order->refresh(), $actor];
    }
}
