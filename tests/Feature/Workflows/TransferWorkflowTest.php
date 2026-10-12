<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use App\Domains\Catalog\Enums\TrackingMode;
use App\Domains\Catalog\Models\Item;
use App\Domains\Inventory\Models\InventoryLot;
use App\Domains\Organization\Actions\CreateBranchAction;
use App\Domains\Organization\Enums\BranchKind;
use App\Domains\Organization\Models\Warehouse;
use App\Domains\Platform\Seeds\DocumentSequencesSeed;
use App\Domains\Platform\Seeds\SeedRunner;
use App\Domains\Platform\Seeds\SodRulesSeed;
use App\Domains\Transfers\Enums\DiscrepancyStatus;
use App\Domains\Transfers\Enums\TransferStatus;
use App\Domains\Transfers\Models\TransferDispatchLine;
use App\Domains\Transfers\Models\TransferOrder;
use App\Domains\Transfers\Services\TransferWorkflow;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsActors;
use Tests\Support\BuildsInventory;
use Tests\TestCase;

/**
 * Chapter 08 §8.4 / §15.5 items 4–8 — batch-verified inter-branch transfers with conservation.
 */
final class TransferWorkflowTest extends TestCase
{
    use BuildsActors;
    use BuildsInventory;

    private TransferWorkflow $flow;

    protected function setUp(): void
    {
        parent::setUp();
        app(SeedRunner::class)->run(DocumentSequencesSeed::class);
        app(SeedRunner::class)->run(SodRulesSeed::class);
        $this->flow = app(TransferWorkflow::class);
    }

    /** HQ depot → retail store, lot-tracked item with 100 units of LOT-123A at HQ. */
    private function scenario(): array
    {
        $hqBranch = app(CreateBranchAction::class)->execute(['code' => 'HQ'.Str::upper(Str::random(3)), 'name' => 'HQ', 'kind' => BranchKind::HEADQUARTERS->value], null);
        $storeBranch = app(CreateBranchAction::class)->execute(['code' => 'ST'.Str::upper(Str::random(3)), 'name' => 'Store', 'kind' => BranchKind::RETAIL_STORE->value], null);
        $hq = $this->warehouse('HQW'.Str::upper(Str::random(3)), $hqBranch);
        $store = $this->warehouse('STW'.Str::upper(Str::random(3)), $storeBranch);
        $item = $this->item(TrackingMode::LOT);
        $lot = $this->lot($item, 'LOT-123A', now()->addYear()->toDateString());
        $this->receive($item, $hq, '100', '8', $lot);

        return [$hq, $store, $item, $lot];
    }

    private function approved(Warehouse $hq, Warehouse $store, Item $item, string $qty = '60'): TransferOrder
    {
        $requester = $this->superAdmin();
        $approver = $this->superAdmin();
        $to = $this->flow->save(['source_warehouse_id' => $hq->id, 'destination_warehouse_id' => $store->id,
            'lines' => [['item_id' => $item->id, 'quantity' => $qty]]], $requester);
        $this->flow->submit($to->id, $requester);

        return $this->flow->approve($to->id, $approver);
    }

    private function dispatch(TransferOrder $to, Warehouse $hq, InventoryLot $lot, string $qty): array
    {
        $dispatcher = $this->superAdmin();
        $outcome = $this->flow->dispatch($to->id, $dispatcher, (string) Str::uuid7(), ['lines' => [[
            'transfer_order_line_id' => $to->lines()->first()->id, 'storage_location_id' => $hq->default_storage_location_id,
            'inventory_lot_id' => $lot->id, 'quantity' => $qty,
        ]]]);

        return [$outcome, $dispatcher];
    }

    public function test_approval_reserves_and_dispatch_moves_stock_into_transit_without_crediting_the_destination(): void
    {
        [$hq, $store, $item, $lot] = $this->scenario();
        $to = $this->approved($hq, $store, $item);

        $this->assertSame('60.000000', (string) DB::table('stock_reservations')->where('reference_id', $to->id)->sum('quantity_reserved'));
        [$outcome] = $this->dispatch($to, $hq, $lot, '60');

        $this->assertSame(TransferStatus::IN_TRANSIT->value, $outcome->body['status']);
        $this->assertSame('40.000000', $this->onHand($item, $hq, lot: $lot));
        $this->assertSame('0', $this->onHand($item, $store), 'destination availability must not increase before receipt');
        $inTransit = DB::table('inventory_balances as b')->join('inventory_locations as l', 'l.id', '=', 'b.inventory_location_id')
            ->where('l.location_type', 'in_transit')->where('b.item_id', $item->id)->where('b.inventory_lot_id', $lot->id)->sum('b.quantity_on_hand');
        $this->assertSame('60.000000', (string) $inTransit, 'dispatched quantity is conserved in transit under the same lot');
        $this->assertSame('consumed', DB::table('stock_reservations')->where('reference_id', $to->id)->value('status'));
    }

    public function test_receipt_verifies_the_batch_and_partial_receipts_keep_the_remainder_in_transit(): void
    {
        [$hq, $store, $item, $lot] = $this->scenario();
        $to = $this->approved($hq, $store, $item);
        $this->dispatch($to, $hq, $lot, '60');
        $receiver = $this->superAdmin();
        $dispatchLine = TransferDispatchLine::query()->whereHas('dispatch', fn ($q) => $q->where('transfer_order_id', $to->id))->firstOrFail();

        try {
            $this->flow->receive($to->id, $receiver, (string) Str::uuid7(), ['lines' => [[
                'transfer_dispatch_line_id' => $dispatchLine->id, 'accepted_quantity' => '10', 'scanned_lot_number' => 'LOT-999',
            ]]]);
            $this->fail('Expected LOT_MISMATCH');
        } catch (DomainException $e) {
            $this->assertSame(ErrorCode::LOT_MISMATCH, $e->errorCode);
        }

        $outcome = $this->flow->receive($to->id, $receiver, (string) Str::uuid7(), ['lines' => [[
            'transfer_dispatch_line_id' => $dispatchLine->id, 'accepted_quantity' => '45', 'scanned_lot_number' => 'lot-123a',
        ]]]);
        $this->assertSame(TransferStatus::PARTIALLY_RECEIVED->value, $outcome->body['status']);
        $this->assertSame('45.000000', $this->onHand($item, $store, lot: $lot));
        $line = $to->lines()->first();
        $this->assertSame('60.000000', $line->dispatched_base_quantity);
        $this->assertSame('45.000000', $line->received_base_quantity);

        // Closing is refused while 15 units are still in transit.
        try {
            $this->flow->close($to->id, $receiver);
            $this->fail('Expected CONSERVATION_VIOLATION');
        } catch (DomainException $e) {
            $this->assertSame(ErrorCode::CONSERVATION_VIOLATION, $e->errorCode);
        }
    }

    public function test_shortage_becomes_a_discrepancy_resolved_by_a_traceable_loss_then_the_transfer_closes(): void
    {
        [$hq, $store, $item, $lot] = $this->scenario();
        $to = $this->approved($hq, $store, $item);
        $this->dispatch($to, $hq, $lot, '60');
        $receiver = $this->superAdmin();
        $dispatchLine = TransferDispatchLine::query()->whereHas('dispatch', fn ($q) => $q->where('transfer_order_id', $to->id))->firstOrFail();

        $outcome = $this->flow->receive($to->id, $receiver, (string) Str::uuid7(), ['lines' => [[
            'transfer_dispatch_line_id' => $dispatchLine->id, 'accepted_quantity' => '55', 'damaged_quantity' => '2',
            'report_shortage' => true, 'scanned_lot_number' => 'LOT-123A',
        ]]]);
        $this->assertSame(TransferStatus::DISCREPANCY_REVIEW->value, $outcome->body['status']);
        $shortage = DB::table('transfer_discrepancies')->where('transfer_order_id', $to->id)->where('discrepancy_type', 'shortage')->first();
        $this->assertSame('3.000000', $shortage->base_quantity);
        $this->assertSame('2.000000', (string) DB::table('inventory_balances')->where('item_id', $item->id)->where('warehouse_id', $store->id)
            ->where('stock_condition', 'damaged')->sum('quantity_on_hand'));

        $manager = $this->superAdmin();
        $resolved = $this->flow->resolveDiscrepancy($to->id, $shortage->id, $manager, (string) Str::uuid7(),
            ['resolution' => 'loss_adjustment', 'reason' => 'Confirmed lost by carrier']);
        $this->assertSame(DiscrepancyStatus::RESOLVED->value, $resolved->body['status']);
        $this->assertSame(TransferStatus::RECEIVED->value, $resolved->body['transfer_status']);
        $this->assertSame(1, DB::table('stock_movements')->where('movement_type', 'transfer_loss')->where('source_document_id', $to->id)->count());

        // dispatched (60) = received (55 + 2 damaged) + resolved (3); nothing left in transit.
        $this->flow->close($to->id, $manager);
        $this->assertSame(TransferStatus::CLOSED, $to->refresh()->status);
        $inTransit = DB::table('inventory_balances as b')->join('inventory_locations as l', 'l.id', '=', 'b.inventory_location_id')
            ->where('l.location_type', 'in_transit')->where('b.item_id', $item->id)->sum('b.quantity_on_hand');
        $this->assertSame('0.000000', (string) $inTransit);
    }

    public function test_the_dispatcher_cannot_also_receive_the_transfer(): void
    {
        [$hq, $store, $item, $lot] = $this->scenario();
        $to = $this->approved($hq, $store, $item);
        [, $dispatcher] = $this->dispatch($to, $hq, $lot, '10');
        $dispatchLine = TransferDispatchLine::query()->whereHas('dispatch', fn ($q) => $q->where('transfer_order_id', $to->id))->firstOrFail();

        try {
            $this->flow->receive($to->id, $dispatcher, (string) Str::uuid7(), ['lines' => [[
                'transfer_dispatch_line_id' => $dispatchLine->id, 'accepted_quantity' => '10', 'scanned_lot_number' => 'LOT-123A',
            ]]]);
            $this->fail('Expected SOD_VIOLATION');
        } catch (DomainException $e) {
            $this->assertSame(ErrorCode::SOD_VIOLATION, $e->errorCode);
        }
    }

    public function test_dispatch_cannot_exceed_the_approved_quantity_or_be_performed_from_the_destination(): void
    {
        [$hq, $store, $item, $lot] = $this->scenario();
        $to = $this->approved($hq, $store, $item, '20');
        try {
            $this->dispatch($to, $hq, $lot, '21');
            $this->fail('Expected QUANTITY_EXCEEDS_OUTSTANDING');
        } catch (DomainException $e) {
            $this->assertSame(ErrorCode::QUANTITY_EXCEEDS_OUTSTANDING, $e->errorCode);
        }

        $storeClerk = $this->actorWith(['transfers.transfer.dispatch'], 'warehouse', $store->id);
        try {
            $this->flow->dispatch($to->id, $storeClerk, (string) Str::uuid7(), ['lines' => [[
                'transfer_order_line_id' => $to->lines()->first()->id, 'storage_location_id' => $hq->default_storage_location_id,
                'inventory_lot_id' => $lot->id, 'quantity' => '5',
            ]]]);
            $this->fail('Expected WAREHOUSE_SCOPE_DENIED');
        } catch (DomainException $e) {
            $this->assertSame(ErrorCode::WAREHOUSE_SCOPE_DENIED, $e->errorCode);
        }
        $this->assertSame('100.000000', $this->onHand($item, $hq, lot: $lot));
    }
}
