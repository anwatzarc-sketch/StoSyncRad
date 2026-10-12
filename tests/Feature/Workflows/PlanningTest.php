<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use App\Domains\Catalog\Models\Item;
use App\Domains\Organization\Enums\BranchKind;
use App\Domains\Organization\Models\Warehouse;
use App\Domains\Planning\Enums\AllocationPlanStatus;
use App\Domains\Planning\Models\AllocationPlanLine;
use App\Domains\Planning\Models\ForecastInput;
use App\Domains\Planning\Services\AllocationPlanWorkflow;
use App\Domains\Planning\Services\DemandService;
use App\Domains\Planning\Services\ReplenishmentService;
use App\Domains\Platform\Seeds\ApprovalPoliciesSeed;
use App\Domains\Platform\Seeds\DocumentSequencesSeed;
use App\Domains\Platform\Seeds\SeedRunner;
use App\Domains\Platform\Seeds\SodRulesSeed;
use App\Domains\Transfers\Enums\TransferStatus;
use App\Domains\Transfers\Models\TransferOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsActors;
use Tests\Support\BuildsInventory;
use Tests\TestCase;

/** Chapter 08 §8.1 — demand from the ledger, replenishment suggestions, allocation plans → transfers. */
final class PlanningTest extends TestCase
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

    private function site(BranchKind $kind): Warehouse
    {
        return $this->warehouse('P'.Str::upper(Str::random(5)), $this->branch('B'.Str::upper(Str::random(4)), $kind));
    }

    private function rule(Item $item, Warehouse $wh, array $attrs): string
    {
        $id = (string) Str::uuid7();
        DB::table('item_reorder_rules')->insert($attrs + ['id' => $id, 'item_id' => $item->id, 'warehouse_id' => $wh->id, 'is_active' => true,
            'replenishment_method' => 'purchase', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    public function test_demand_history_and_daily_demand_come_from_the_ledger(): void
    {
        $wh = $this->site(BranchKind::RETAIL_STORE);
        $item = $this->item();
        $this->receive($item, $wh, '200');
        $this->issue($item, $wh, '30');
        $this->issue($item, $wh, '15');
        $demand = app(DemandService::class);

        $history = $demand->history($item->id, null, $wh->id, now()->subMonth()->toDateString(), now()->toDateString());
        $this->assertSame('45.000000', $history[0]['gross']);
        $this->assertSame('0.500000', $demand->dailyDemand($item->id, null, $wh->id)['daily'], '45 over a 90-day window');

        ForecastInput::query()->forceCreate(['item_id' => $item->id, 'warehouse_id' => $wh->id, 'method' => 'manual', 'manual_daily_demand' => '4',
            'horizon_days' => 10, 'window_days' => 30, 'reason' => 'Promotion']);
        $this->assertSame(['daily' => '4.000000', 'method' => 'manual', 'window_days' => 30, 'horizon_days' => 10], $demand->dailyDemand($item->id, null, $wh->id));
    }

    public function test_replenishment_suggestion_explains_its_quantities_and_converts_to_a_purchase_request(): void
    {
        $wh = $this->site(BranchKind::HEADQUARTERS);
        $item = $this->item();
        $this->receive($item, $wh, '8');
        $ruleId = $this->rule($item, $wh, ['reorder_point' => '10', 'max_qty' => '50', 'reorder_qty' => '20', 'multiple_of_qty' => '12']);
        $planner = $this->superAdmin();
        $service = app(ReplenishmentService::class);

        $suggestions = $service->suggestions($planner, ['warehouse_id' => $wh->id]);
        $this->assertCount(1, $suggestions);
        $s = $suggestions[0];
        $this->assertSame('8.000000', $s['available']);
        $this->assertSame('8.000000', $s['projected']);
        $this->assertTrue($s['below_reorder_point']);
        $this->assertSame('48.000000', $s['proposed_quantity'], 'max(20, 50 − 8 = 42) rounded up to a multiple of 12');

        $converted = $service->convert($ruleId, $planner);
        $this->assertSame('purchase_request', $converted['type']);
        $this->assertSame('48.000000', (string) DB::table('purchase_request_lines')->where('purchase_request_id', $converted['id'])->value('base_quantity'));
    }

    public function test_priority_allocation_plan_is_approved_by_another_person_and_released_into_approved_transfers(): void
    {
        $hq = $this->site(BranchKind::HEADQUARTERS);
        $storeA = $this->site(BranchKind::RETAIL_STORE);
        $storeB = $this->site(BranchKind::RETAIL_STORE);
        $item = $this->item();
        $this->receive($item, $hq, '100', '3');
        $this->rule($item, $storeA, ['reorder_point' => '10', 'max_qty' => '70']);
        $this->rule($item, $storeB, ['reorder_point' => '10', 'max_qty' => '60']);
        $planner = $this->superAdmin();
        $approver = $this->superAdmin();
        $flow = app(AllocationPlanWorkflow::class);

        $plan = $flow->save(['warehouse_id' => $hq->id, 'policy' => 'priority_order', 'lines' => [
            ['destination_warehouse_id' => $storeA->id, 'item_id' => $item->id, 'priority' => 1],
            ['destination_warehouse_id' => $storeB->id, 'item_id' => $item->id, 'priority' => 2, 'cap_quantity' => '25'],
        ]], $planner);
        $explain = $flow->suggest($plan->id, $planner);
        $byDest = collect($explain)->keyBy('destination_warehouse_id');
        $this->assertSame('70.000000', $byDest[$storeA->id]['suggested'], 'first priority fills to max');
        $this->assertSame('25.000000', $byDest[$storeB->id]['suggested'], 'second gets the remainder, capped at 25');

        $flow->submit($plan->id, $planner);
        $flow->approve($plan->id, $approver);
        $flow->release($plan->id, $planner);

        $this->assertSame(AllocationPlanStatus::RELEASED, $plan->refresh()->status);
        $transfers = TransferOrder::query()->where('allocation_plan_id', $plan->id)->get();
        $this->assertCount(2, $transfers);
        $this->assertTrue($transfers->every(fn ($t) => $t->status === TransferStatus::APPROVED));
        $this->assertSame('95.000000', (string) DB::table('stock_reservations')->whereIn('reference_id', $transfers->pluck('id'))->sum('quantity_reserved'));
        $this->assertSame(0, AllocationPlanLine::query()->where('allocation_plan_id', $plan->id)->whereNull('transfer_order_id')->count());
        $this->assertSame('100.000000', $this->onHand($item, $hq), 'the plan never moves stock');
    }
}
