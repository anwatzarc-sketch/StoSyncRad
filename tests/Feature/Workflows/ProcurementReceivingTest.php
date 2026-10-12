<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use App\Domains\Catalog\Enums\QcPolicy;
use App\Domains\Catalog\Enums\TrackingMode;
use App\Domains\Inventory\Enums\StockCondition;
use App\Domains\Partners\Actions\SavePartnerAction;
use App\Domains\Partners\Models\Partner;
use App\Domains\Platform\Seeds\DocumentSequencesSeed;
use App\Domains\Platform\Seeds\SeedRunner;
use App\Domains\Platform\Seeds\SodRulesSeed;
use App\Domains\Procurement\Enums\PurchaseOrderStatus;
use App\Domains\Procurement\Services\PurchaseOrderWorkflow;
use App\Domains\Receiving\Enums\GoodsReceiptStatus;
use App\Domains\Receiving\Services\GoodsReceiptWorkflow;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsActors;
use Tests\Support\BuildsInventory;
use Tests\TestCase;

final class ProcurementReceivingTest extends TestCase
{
    use BuildsActors;
    use BuildsInventory;

    private function supplier(): Partner
    {
        return app(SavePartnerAction::class)->execute(['code' => 'SUP'.Str::upper(Str::random(5)), 'name' => 'Supplier', 'partner_types' => ['supplier']], null);
    }

    private function approvedOrder(string $qty = '100', string $price = '12.50', ?\App\Domains\Catalog\Models\Item $item = null): array
    {
        app(SeedRunner::class)->run(DocumentSequencesSeed::class);
        app(SeedRunner::class)->run(SodRulesSeed::class);
        $wh = $this->warehouse('PR'.Str::upper(Str::random(4)));
        $item ??= $this->item();
        $buyer = $this->superAdmin();
        $approver = $this->superAdmin();
        $po = app(PurchaseOrderWorkflow::class)->save(['warehouse_id' => $wh->id, 'supplier_id' => $this->supplier()->id,
            'lines' => [['item_id' => $item->id, 'quantity' => $qty, 'unit_price' => $price, 'discount_percent' => '0', 'tax_percent' => '0']]], $buyer);
        app(PurchaseOrderWorkflow::class)->submit($po->id, $buyer);
        app(PurchaseOrderWorkflow::class)->approve($po->id, $approver);

        return [$po->refresh(), $wh, $item, $buyer, $approver];
    }

    public function test_order_totals_are_computed_by_the_server_and_creator_cannot_approve(): void
    {
        app(SeedRunner::class)->run(DocumentSequencesSeed::class);
        app(SeedRunner::class)->run(SodRulesSeed::class);
        $wh = $this->warehouse('PT'.Str::upper(Str::random(4)));
        $item = $this->item();
        $buyer = $this->superAdmin();
        $po = app(PurchaseOrderWorkflow::class)->save(['warehouse_id' => $wh->id, 'supplier_id' => $this->supplier()->id, 'charges_total' => '10',
            'lines' => [['item_id' => $item->id, 'quantity' => '4', 'unit_price' => '25', 'discount_percent' => '10', 'tax_percent' => '15']]], $buyer);

        // 4 × 25 = 100, −10% = 90, +15% = 103.50, + 10 charges
        $this->assertSame('103.5000', $po->subtotal);
        $this->assertSame('113.5000', $po->total);
        $this->assertMatchesRegularExpression('/^PO-\d{4}-\d{6}$/', $po->document_number);

        app(PurchaseOrderWorkflow::class)->submit($po->id, $buyer);
        try {
            app(PurchaseOrderWorkflow::class)->approve($po->id, $buyer);
            $this->fail('Expected SOD_VIOLATION');
        } catch (DomainException $e) {
            $this->assertSame(ErrorCode::SOD_VIOLATION, $e->errorCode);
        }
    }

    public function test_receipt_posts_stock_at_po_cost_and_partially_receives_the_order(): void
    {
        [$po, $wh, $item, , $approver] = $this->approvedOrder('100', '12.50');
        $receiver = $this->superAdmin();
        $poLine = $po->lines()->first();

        $gr = app(GoodsReceiptWorkflow::class)->save(['purchase_order_id' => $po->id, 'lines' => [
            ['purchase_order_line_id' => $poLine->id, 'item_id' => $item->id, 'received_quantity' => '40', 'rejected_quantity' => '2'],
        ]], $receiver);
        $outcome = app(GoodsReceiptWorkflow::class)->post($gr->id, $receiver, (string) Str::uuid7());

        $this->assertSame('posted', $outcome->body['status']);
        $this->assertSame(GoodsReceiptStatus::POSTED, $gr->refresh()->status);
        $this->assertSame(PurchaseOrderStatus::PARTIALLY_RECEIVED, $po->refresh()->status);
        $this->assertSame('38.000000', $po->lines()->first()->received_base_quantity);
        $this->assertSame('2.000000', $po->lines()->first()->rejected_base_quantity);
        // Received into the receiving area at the PO price.
        $this->assertSame('38.000000', (string) DB::table('inventory_balances')->where('item_id', $item->id)->where('warehouse_id', $wh->id)->sum('quantity_on_hand'));
        $this->assertSame('12.50000000', DB::table('stock_movement_lines')->where('item_id', $item->id)->value('unit_cost_snapshot'));
        // A put-away task was raised from the receiving area.
        $this->assertSame(1, DB::table('putaway_tasks')->where('goods_receipt_id', $gr->id)->count());
    }

    public function test_over_receipt_beyond_tolerance_is_rejected_without_partial_effects(): void
    {
        [$po, $wh, $item] = $this->approvedOrder('10');
        $receiver = $this->superAdmin();
        $gr = app(GoodsReceiptWorkflow::class)->save(['purchase_order_id' => $po->id, 'lines' => [
            ['purchase_order_line_id' => $po->lines()->first()->id, 'item_id' => $item->id, 'received_quantity' => '11'],
        ]], $receiver);

        try {
            app(GoodsReceiptWorkflow::class)->post($gr->id, $receiver, (string) Str::uuid7());
            $this->fail('Expected OVER_RECEIPT');
        } catch (DomainException $e) {
            $this->assertSame(ErrorCode::OVER_RECEIPT, $e->errorCode);
        }
        $this->assertSame(GoodsReceiptStatus::DRAFT, $gr->refresh()->status);
        $this->assertSame('0.000000', $po->lines()->first()->received_base_quantity);
        $this->assertSame(0, DB::table('inventory_balances')->where('item_id', $item->id)->where('quantity_on_hand', '>', 0)->count());

        // With a 10 % tolerance configured for the warehouse, the same receipt posts.
        $this->setConfig('receiving.over_receipt.tolerance_percent', '10', \App\Domains\Platform\Config\ConfigScopeType::WAREHOUSE, $wh->id);
        app(GoodsReceiptWorkflow::class)->post($gr->id, $receiver, (string) Str::uuid7());
        $this->assertSame(PurchaseOrderStatus::FULLY_RECEIVED, $po->refresh()->status);
    }

    public function test_lot_tracked_quarantine_items_enter_quarantine_with_a_pending_lot(): void
    {
        $item = $this->item(TrackingMode::LOT);
        $item->forceFill(['quality_control_policy' => QcPolicy::QUARANTINE_ON_RECEIPT])->save();
        [$po, $wh] = $this->approvedOrder('20', '5', $item);
        $receiver = $this->superAdmin();
        $gr = app(GoodsReceiptWorkflow::class)->save(['purchase_order_id' => $po->id, 'lines' => [
            ['purchase_order_line_id' => $po->lines()->first()->id, 'item_id' => $item->id, 'received_quantity' => '20',
                'lot_number' => 'BATCH-77', 'expiry_date' => now()->addYear()->toDateString()],
        ]], $receiver);
        app(GoodsReceiptWorkflow::class)->post($gr->id, $receiver, (string) Str::uuid7());

        $lot = DB::table('inventory_lots')->where('item_id', $item->id)->where('lot_number', 'BATCH-77')->first();
        $this->assertSame('pending', $lot->qc_status);
        $this->assertSame('20.000000', $this->onHand($item, $wh, StockCondition::QUARANTINE));
        $this->assertSame('0', $this->onHand($item, $wh, StockCondition::AVAILABLE));
    }

    public function test_posting_twice_with_the_same_key_replays_and_a_new_key_is_refused(): void
    {
        [$po, , $item] = $this->approvedOrder('10');
        $receiver = $this->superAdmin();
        $gr = app(GoodsReceiptWorkflow::class)->save(['purchase_order_id' => $po->id, 'lines' => [
            ['purchase_order_line_id' => $po->lines()->first()->id, 'item_id' => $item->id, 'received_quantity' => '5'],
        ]], $receiver);
        $key = (string) Str::uuid7();
        $first = app(GoodsReceiptWorkflow::class)->post($gr->id, $receiver, $key);
        $second = app(GoodsReceiptWorkflow::class)->post($gr->id, $receiver, $key);
        $this->assertTrue($second->replayed);
        $this->assertSame($first->body['stock_movement_id'], $second->body['stock_movement_id']);

        try {
            app(GoodsReceiptWorkflow::class)->post($gr->id, $receiver, (string) Str::uuid7());
            $this->fail('Expected ALREADY_POSTED');
        } catch (DomainException $e) {
            $this->assertSame(ErrorCode::ALREADY_POSTED, $e->errorCode);
        }
        $this->assertSame(1, DB::table('stock_movements')->where('source_document_id', $gr->id)->count());
    }

    public function test_receiving_requires_warehouse_scoped_permission(): void
    {
        [$po, , $item] = $this->approvedOrder('10');
        $otherWarehouse = $this->warehouse('OW'.Str::upper(Str::random(4)));
        $clerk = $this->actorWith(['receiving.goods_receipt.create', 'receiving.goods_receipt.post'], 'warehouse', $otherWarehouse->id);

        try {
            app(GoodsReceiptWorkflow::class)->save(['purchase_order_id' => $po->id, 'lines' => [
                ['purchase_order_line_id' => $po->lines()->first()->id, 'item_id' => $item->id, 'received_quantity' => '5'],
            ]], $clerk);
            $this->fail('Expected WAREHOUSE_SCOPE_DENIED');
        } catch (DomainException $e) {
            $this->assertSame(ErrorCode::WAREHOUSE_SCOPE_DENIED, $e->errorCode);
        }
    }
}
