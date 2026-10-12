<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domains\Catalog\Enums\TrackingMode;
use App\Domains\Inventory\Documents\ConditionChangeWorkflow;
use App\Domains\Inventory\Documents\OpeningBalanceWorkflow;
use App\Domains\Inventory\Documents\RelocationWorkflow;
use App\Domains\Inventory\Documents\ScrapWorkflow;
use App\Domains\Inventory\Enums\LotLockStatus;
use App\Domains\Inventory\Enums\LotQcStatus;
use App\Domains\Inventory\Enums\MovementStatus;
use App\Domains\Inventory\Enums\ReconciliationFindingStatus;
use App\Domains\Inventory\Enums\ReconciliationRunStatus;
use App\Domains\Inventory\Enums\SerialStatus;
use App\Domains\Inventory\Enums\StockCondition;
use App\Domains\Inventory\Models\ItemSerial;
use App\Domains\Inventory\Models\ReconciliationFinding;
use App\Domains\Inventory\Services\ExpiryMonitor;
use App\Domains\Inventory\Services\RecallService;
use App\Domains\Inventory\Services\ReconciliationService;
use App\Domains\Inventory\Services\ReservationService;
use App\Domains\Inventory\Services\ReversalService;
use App\Domains\Inventory\Enums\ReservationReferenceType;
use App\Domains\Organization\Actions\CreateStorageLocationAction;
use App\Domains\Organization\Enums\StorageLocationUsage;
use App\Domains\Organization\Models\Warehouse;
use App\Domains\Platform\Seeds\ApprovalPoliciesSeed;
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
 * Chapter 07 §7.7, §7.10–7.12, §7.14 — opening balances, relocation, condition change, scrap,
 * reversal blocked downstream, recall locks, reconciliation never touching the ledger, expiry.
 */
final class InventoryDocumentsTest extends TestCase
{
    use BuildsActors;
    use BuildsInventory;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([DocumentSequencesSeed::class, SodRulesSeed::class, ApprovalPoliciesSeed::class] as $seed) {
            app(SeedRunner::class)->run($seed);
        }
    }

    private function key(): string
    {
        return (string) Str::uuid7();
    }

    private function assertDomainError(ErrorCode $code, callable $fn): DomainException
    {
        try {
            $fn();
        } catch (DomainException $e) {
            $this->assertSame($code, $e->errorCode, $e->getMessage());

            return $e;
        }
        $this->fail("Expected {$code->value}");
    }

    private function bin(Warehouse $wh, string $code): string
    {
        return app(CreateStorageLocationAction::class)->execute(['warehouse_id' => $wh->id, 'parent_id' => $wh->default_storage_location_id,
            'code' => $code, 'name' => "Bin $code", 'location_type' => 'bin'], null)->id;
    }

    public function test_opening_balance_needs_a_second_person_registers_lots_and_serials_and_respects_the_backdating_window(): void
    {
        $wh = $this->warehouse('OB'.Str::upper(Str::random(4)));
        $lotItem = $this->item(TrackingMode::LOT);
        $serialItem = $this->item(TrackingMode::SERIAL);
        $flow = app(OpeningBalanceWorkflow::class);
        $preparer = $this->superAdmin();
        $approver = $this->superAdmin();

        $doc = $flow->save(['warehouse_id' => $wh->id, 'cutoff_date' => now()->subDays(30)->toDateString(), 'lines' => [
            ['item_id' => $lotItem->id, 'storage_location_id' => $wh->default_storage_location_id, 'quantity' => '40', 'unit_cost' => '2.5',
                'lot_number' => 'OB-LOT-1', 'expiry_date' => now()->addYear()->toDateString()],
            ['item_id' => $serialItem->id, 'storage_location_id' => $wh->default_storage_location_id, 'quantity' => '2', 'unit_cost' => '300',
                'serial_numbers' => ['OB-SN-1', 'OB-SN-2']],
        ]], $preparer);
        $flow->submit($doc->id, $preparer);
        $this->assertDomainError(ErrorCode::SOD_VIOLATION, fn () => $flow->approve($doc->id, $preparer));
        $flow->approve($doc->id, $approver);
        $this->assertDomainError(ErrorCode::VALIDATION_FAILED, fn () => $flow->post($doc->id, $approver, $this->key()));   // 30 days > 7-day window

        $this->setConfig('stock.backdating_window_days', 45);
        $outcome = $flow->post($doc->id, $approver, $this->key());
        $this->assertSame('posted', $outcome->body['status']);
        $this->assertSame('40.000000', $this->onHand($lotItem, $wh));
        $this->assertSame('2.000000', $this->onHand($serialItem, $wh));
        $this->assertSame(now()->subDays(30)->toDateString(), DB::table('stock_movements')->where('id', $outcome->body['stock_movement_ids'][0])->value('business_date'));
        $this->assertSame(SerialStatus::IN_STOCK, ItemSerial::query()->where('serial_number', 'OB-SN-1')->firstOrFail()->lifecycle_status);
        $this->assertSame('100.0000', (string) DB::table('stock_movement_lines')->where('stock_movement_id', $outcome->body['stock_movement_ids'][0])
            ->where('item_id', $lotItem->id)->value('total_cost_snapshot'));
    }

    public function test_relocation_moves_between_bins_and_condition_change_release_needs_qc_released_lots(): void
    {
        $wh = $this->warehouse('RL'.Str::upper(Str::random(4)));
        $item = $this->item(TrackingMode::LOT);
        $released = $this->lot($item, 'REL-1', now()->addYear()->toDateString());
        $pending = $this->lot($item, 'PEN-1', now()->addYear()->toDateString(), LotQcStatus::PENDING);
        $this->receive($item, $wh, '10', '5', $released);
        $this->receive($item, $wh, '4', '5', $released, condition: StockCondition::QUARANTINE);
        $this->receive($item, $wh, '3', '5', $pending, condition: StockCondition::QUARANTINE);
        $actor = $this->superAdmin();
        $binA = $this->bin($wh, 'A1');

        $relocation = app(RelocationWorkflow::class);
        $doc = $relocation->save(['warehouse_id' => $wh->id, 'lines' => [['item_id' => $item->id, 'from_storage_location_id' => $wh->default_storage_location_id,
            'to_storage_location_id' => $binA, 'inventory_lot_id' => $released->id, 'quantity' => '6']]], $actor);
        $relocation->post($doc->id, $actor, $this->key());
        $atBin = DB::table('inventory_balances as b')->join('inventory_locations as il', 'il.id', '=', 'b.inventory_location_id')
            ->where('il.storage_location_id', $binA)->where('b.item_id', $item->id)->value('b.quantity_on_hand');
        $this->assertSame('6.000000', (string) $atBin);
        $this->assertSame('10.000000', $this->onHand($item, $wh, lot: $released), 'relocation keeps the warehouse total');

        $changes = app(ConditionChangeWorkflow::class);
        $this->assertDomainError(ErrorCode::LOT_NOT_RELEASED, fn () => $changes->save(['warehouse_id' => $wh->id, 'lines' => [['item_id' => $item->id,
            'storage_location_id' => $wh->default_storage_location_id, 'inventory_lot_id' => $pending->id, 'from_condition' => 'quarantine', 'to_condition' => 'available', 'quantity' => '3']]], $actor));
        $release = $changes->save(['warehouse_id' => $wh->id, 'reason' => 'Inspection passed', 'lines' => [['item_id' => $item->id,
            'storage_location_id' => $wh->default_storage_location_id, 'inventory_lot_id' => $released->id, 'from_condition' => 'quarantine', 'to_condition' => 'available', 'quantity' => '4']]], $actor);
        $clerk = $this->actorWith(['inventory.condition.change']);
        $this->assertDomainError(ErrorCode::WAREHOUSE_SCOPE_DENIED, fn () => $changes->post($release->id, $clerk, $this->key()));
        $changes->post($release->id, $actor, $this->key());
        $this->assertSame('14.000000', $this->onHand($item, $wh, lot: $released));
        $this->assertSame('3.000000', $this->onHand($item, $wh, StockCondition::QUARANTINE));
    }

    public function test_scrap_is_approved_then_written_off_from_damaged_stock(): void
    {
        $wh = $this->warehouse('SC'.Str::upper(Str::random(4)));
        $item = $this->item();
        $this->receive($item, $wh, '5', '20', condition: StockCondition::DAMAGED);
        $flow = app(ScrapWorkflow::class);
        $clerk = $this->superAdmin();
        $manager = $this->superAdmin();
        $doc = $flow->save(['warehouse_id' => $wh->id, 'reason' => 'Crushed pallets', 'lines' => [['item_id' => $item->id,
            'storage_location_id' => $wh->default_storage_location_id, 'quantity' => '5']]], $clerk);
        $this->assertDomainError(ErrorCode::INVALID_STATE_TRANSITION, fn () => $flow->post($doc->id, $manager, $this->key()));
        $flow->submit($doc->id, $clerk);
        $flow->approve($doc->id, $manager);
        $outcome = $flow->post($doc->id, $manager, $this->key());
        $this->assertSame('0.000000', $this->onHand($item, $wh, StockCondition::DAMAGED));
        $this->assertSame('scrap', DB::table('stock_movements')->where('id', $outcome->body['stock_movement_ids'][0])->value('movement_type'));
    }

    public function test_reversal_is_blocked_downstream_and_partial_reversal_needs_a_second_person(): void
    {
        $wh = $this->warehouse('RV'.Str::upper(Str::random(4)));
        $item = $this->item();
        $receipt = $this->receive($item, $wh, '10', '4');
        $this->issue($item, $wh, '4');
        $movementId = $receipt->body['movement_id'];
        $reversals = app(ReversalService::class);
        $requester = $this->superAdmin();
        $approver = $this->superAdmin();

        $e = $this->assertDomainError(ErrorCode::REVERSAL_BLOCKED_DOWNSTREAM, fn () => $reversals->request($movementId, $requester, ['reason' => 'Wrong supplier']));
        $this->assertSame('issue', $e->details['lines'][0]['dependents'][0]['movement_type']);

        $line = DB::table('stock_movement_lines')->where('stock_movement_id', $movementId)->value('id');
        $request = $reversals->request($movementId, $requester, ['reason' => 'Over-received', 'lines' => [['line_id' => $line, 'quantity' => '5']]]);
        $this->assertDomainError(ErrorCode::SOD_VIOLATION, fn () => $reversals->approve($request->id, $requester, $this->key()));
        $outcome = $reversals->approve($request->id, $approver, $this->key());

        $this->assertSame('partially_reversed', $outcome->body['original_status']);
        $this->assertSame('1.000000', $this->onHand($item, $wh));
        $this->assertSame(MovementStatus::PARTIALLY_REVERSED->value, DB::table('stock_movements')->where('id', $movementId)->value('status'));
        $this->assertSame('5.000000', (string) DB::table('stock_movement_lines')->where('id', $line)->value('reversed_quantity'));
        $this->assertSame('4.00000000', (string) DB::table('stock_movement_lines')->where('stock_movement_id', $outcome->body['stock_movement_id'])->value('unit_cost_snapshot'),
            'reversals use the original cost');
        $this->assertDomainError(ErrorCode::QUANTITY_EXCEEDS_OUTSTANDING, fn () => $reversals->request($movementId, $requester,
            ['reason' => 'Again', 'lines' => [['line_id' => $line, 'quantity' => '6']]]));
    }

    public function test_recall_locks_lots_everywhere_releases_reservations_and_unlocks_on_close(): void
    {
        $wh = $this->warehouse('RC'.Str::upper(Str::random(4)));
        $item = $this->item(TrackingMode::LOT);
        $lot = $this->lot($item, 'BAD-1', now()->addYear()->toDateString());
        $this->receive($item, $wh, '8', '3', $lot);
        $actor = $this->superAdmin();
        DB::transaction(fn () => app(ReservationService::class)->reserveFromWarehouse(ReservationReferenceType::MANUAL, (string) Str::uuid7(), (string) Str::uuid7(),
            $item, null, $wh->id, '2', $actor));
        $this->issue($item, $wh, '1', $lot);

        $recalls = app(RecallService::class);
        $recall = $recalls->initiate(['reason' => 'Contamination', 'lot_ids' => [$lot->id]], $actor);
        $this->assertSame(LotLockStatus::LOCKED_RECALL, $lot->refresh()->lock_status);
        $this->assertSame('0.000000', (string) DB::table('inventory_balances')->where('inventory_lot_id', $lot->id)->sum('quantity_reserved'));
        $this->assertDomainError(ErrorCode::LOT_LOCKED, fn () => $this->issue($item, $wh, '1', $lot));

        $affected = $recalls->affected($recall->id);
        $this->assertSame('7.000000', (string) $affected['positions'][0]['quantity_on_hand']);
        $this->assertCount(1, $affected['recipients']);

        $recalls->close($recall->id, $actor, 'Supplier cleared the batch');
        $this->assertSame(LotLockStatus::UNLOCKED, $lot->refresh()->lock_status);
        $this->issue($item, $wh, '1', $lot);
    }

    public function test_reconciliation_finds_projection_drift_and_repair_never_touches_the_ledger(): void
    {
        $wh = $this->warehouse('RE'.Str::upper(Str::random(4)));
        $item = $this->item();
        $this->receive($item, $wh, '12');
        $this->issue($item, $wh, '2');
        $service = app(ReconciliationService::class);
        $actor = $this->superAdmin();

        $clean = $service->run($actor, ['item_ids' => [$item->id]]);
        $this->assertSame(ReconciliationRunStatus::COMPLETED_CLEAN, $clean->status);

        DB::table('inventory_balances')->where('item_id', $item->id)->where('warehouse_id', $wh->id)->update(['quantity_on_hand' => 13]);   // simulated drift
        $ledgerBefore = DB::table('stock_movement_lines')->selectRaw("count(*) AS n, md5(string_agg(id::text || quantity::text, ',' ORDER BY id)) AS h")->first();
        $run = $service->run($actor, ['item_ids' => [$item->id]]);
        $this->assertSame(ReconciliationRunStatus::COMPLETED_WITH_FINDINGS, $run->status);
        $finding = ReconciliationFinding::query()->where('reconciliation_run_id', $run->id)->firstOrFail();
        $this->assertSame('3.000000', (string) $finding->difference);

        $service->repair($finding->id, $actor, 'Manual edit by DBA on ticket 42');
        $this->assertSame(ReconciliationFindingStatus::REPAIRED, $finding->refresh()->status);
        $this->assertSame('10.000000', $this->onHand($item, $wh));
        $ledgerAfter = DB::table('stock_movement_lines')->selectRaw("count(*) AS n, md5(string_agg(id::text || quantity::text, ',' ORDER BY id)) AS h")->first();
        $this->assertEquals($ledgerBefore, $ledgerAfter, 'repairs never touch the ledger');
        $this->assertSame(ReconciliationRunStatus::COMPLETED_CLEAN, $service->run($actor, ['item_ids' => [$item->id]])->status);
    }

    public function test_expiry_monitor_moves_expired_lots_once_and_raises_horizon_alerts(): void
    {
        $wh = $this->warehouse('EX'.Str::upper(Str::random(4)));
        $item = $this->item(TrackingMode::LOT);
        $expired = $this->lot($item, 'EXP-1', now()->addDays(2)->toDateString());
        $soon = $this->lot($item, 'SOON-1', now()->addDays(7)->toDateString());
        $this->receive($item, $wh, '6', '1', $expired);
        $this->receive($item, $wh, '2', '1', $soon);
        DB::table('inventory_lots')->where('id', $expired->id)->update(['expiry_date' => now()->subDay()->toDateString()]);

        $first = app(ExpiryMonitor::class)->run();
        $this->assertSame(1, $first['expired_positions']);
        $this->assertSame('6.000000', $this->onHand($item, $wh, StockCondition::EXPIRED));
        $this->assertSame('2.000000', $this->onHand($item, $wh));
        $this->assertGreaterThanOrEqual(1, $first['alerts']);

        $second = app(ExpiryMonitor::class)->run();
        $this->assertSame(0, $second['expired_positions'], 'idempotent');
        $this->assertSame(0, $second['alerts']);
    }
}
