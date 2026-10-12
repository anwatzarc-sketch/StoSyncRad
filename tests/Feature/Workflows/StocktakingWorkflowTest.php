<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use App\Domains\Catalog\Enums\TrackingMode;
use App\Domains\Inventory\Enums\StockCondition;
use App\Domains\Organization\Enums\BranchKind;
use App\Domains\Organization\Models\Warehouse;
use App\Domains\Platform\Seeds\ApprovalPoliciesSeed;
use App\Domains\Platform\Seeds\DocumentSequencesSeed;
use App\Domains\Platform\Seeds\SeedRunner;
use App\Domains\Platform\Seeds\SodRulesSeed;
use App\Domains\Stocktaking\Enums\CountLineStatus;
use App\Domains\Stocktaking\Enums\StockAdjustmentStatus;
use App\Domains\Stocktaking\Enums\StocktakeStatus;
use App\Domains\Stocktaking\Models\StockAdjustment;
use App\Domains\Stocktaking\Models\StocktakeLine;
use App\Domains\Stocktaking\Services\StockAdjustmentWorkflow;
use App\Domains\Stocktaking\Services\StocktakeWorkflow;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsActors;
use Tests\Support\BuildsInventory;
use Tests\TestCase;

/**
 * Chapter 08 §8.10–8.11 / §8.15 — stocktake freeze/snapshot variance, recount thresholds,
 * adjustment SoD and limits.
 */
final class StocktakingWorkflowTest extends TestCase
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

    private function site(): Warehouse
    {
        return $this->warehouse('W'.Str::upper(Str::random(5)), $this->branch('B'.Str::upper(Str::random(4)), BranchKind::HEADQUARTERS));
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

    /** @return array<string, StocktakeLine> */
    private function linesByItem(string $stocktakeId): array
    {
        return StocktakeLine::query()->where('stocktake_id', $stocktakeId)->get()->keyBy('item_id')->all();
    }

    public function test_freeze_policy_blocks_postings_and_variance_posts_once_through_an_adjustment(): void
    {
        $wh = $this->site();
        $a = $this->item();
        $b = $this->item();
        $this->receive($a, $wh, '100', '2');
        $this->receive($b, $wh, '10', '50');
        $flow = app(StocktakeWorkflow::class);
        $planner = $this->superAdmin();
        $counter = $this->superAdmin();
        $supervisor = $this->superAdmin();

        $st = $flow->save(['warehouse_id' => $wh->id, 'stocktake_type' => 'full', 'freeze_policy' => 'freeze_positions'], $planner);
        $flow->start($st->id, $planner);
        $this->assertDomainError(ErrorCode::POSITION_FROZEN, fn () => $this->issue($a, $wh, '1'));

        $lines = $this->linesByItem($st->id);
        $this->assertCount(2, $lines);
        // Item A: 99 counted (1% variance, below the 2% recount threshold); item B: 10 counted.
        $flow->count($st->id, $counter, ['counts' => [
            ['line_id' => $lines[$a->id]->id, 'quantity' => '99'], ['line_id' => $lines[$b->id]->id, 'quantity' => '10'],
        ]]);
        $flow->finishCounting($st->id, $counter);
        $this->assertSame(StocktakeStatus::REVIEW, $st->refresh()->status);
        $this->assertSame('-1.000000', (string) $lines[$a->id]->refresh()->variance_quantity);
        $this->assertSame('-2.0000', (string) $lines[$a->id]->variance_value);

        $flow->review($st->id, $supervisor);
        $this->assertSame(StocktakeStatus::APPROVED, $st->refresh()->status, 'small variance needs no extra approval');
        $outcome = $flow->post($st->id, $supervisor, $this->key());

        $this->assertSame('posted', $outcome->body['status']);
        $this->assertSame('99.000000', $this->onHand($a, $wh));
        $this->assertSame('10.000000', $this->onHand($b, $wh));
        $adj = StockAdjustment::query()->findOrFail($outcome->body['stock_adjustment_id']);
        $this->assertSame(StockAdjustmentStatus::POSTED, $adj->status);
        $this->assertSame('-2.0000', (string) $adj->total_value);
        $this->assertSame(0, DB::table('inventory_balances')->where('frozen_by_stocktake_id', $st->id)->count(), 'posting releases the freeze');
        $this->issue($a, $wh, '1');
        $this->assertDomainError(ErrorCode::ALREADY_POSTED, fn () => $flow->post($st->id, $supervisor, $this->key()));
    }

    public function test_snapshot_mode_reconciles_movements_between_snapshot_and_count(): void
    {
        $wh = $this->site();
        $item = $this->item();
        $this->receive($item, $wh, '50', '3');
        $flow = app(StocktakeWorkflow::class);
        $actor = $this->superAdmin();
        $st = $flow->save(['warehouse_id' => $wh->id, 'freeze_policy' => 'snapshot_and_reconcile', 'scope' => ['item_ids' => [$item->id]]], $actor);
        $flow->start($st->id, $actor);
        $this->travel(1)->seconds();
        $this->issue($item, $wh, '5');          // operations continue during the count
        $this->travel(1)->seconds();
        $line = StocktakeLine::query()->where('stocktake_id', $st->id)->firstOrFail();
        $flow->count($st->id, $actor, ['counts' => [['line_id' => $line->id, 'quantity' => '45']]]);
        $flow->finishCounting($st->id, $actor);

        $line->refresh();
        $this->assertSame('50.000000', (string) $line->snapshot_quantity);
        $this->assertSame('-5.000000', (string) $line->movement_since_snapshot);
        $this->assertSame('0.000000', (string) $line->variance_quantity, 'the issue during counting is not a variance');
        $this->assertSame(StocktakeStatus::REVIEW, $st->refresh()->status);
    }

    public function test_large_variance_requires_recount_then_supervisor_approval_by_a_non_counter(): void
    {
        $wh = $this->site();
        $item = $this->item();
        $this->receive($item, $wh, '100', '200');
        $flow = app(StocktakeWorkflow::class);
        $counter = $this->superAdmin();
        $approver = $this->superAdmin();
        $st = $flow->save(['warehouse_id' => $wh->id], $counter);
        $flow->start($st->id, $counter);
        $line = StocktakeLine::query()->where('stocktake_id', $st->id)->firstOrFail();

        $flow->count($st->id, $counter, ['counts' => [['line_id' => $line->id, 'quantity' => '80']]]);
        $flow->finishCounting($st->id, $counter);
        $this->assertSame(StocktakeStatus::RECOUNT_REQUIRED, $st->refresh()->status);
        $this->assertSame(CountLineStatus::RECOUNT_REQUIRED, $line->refresh()->count_status);

        $flow->count($st->id, $counter, ['counts' => [['line_id' => $line->id, 'quantity' => '81']]]);
        $flow->finishCounting($st->id, $counter);
        $this->assertSame(StocktakeStatus::REVIEW, $st->refresh()->status, 'a recounted line is not recounted again');
        $this->assertSame('-19.000000', (string) $line->refresh()->variance_quantity);

        $flow->review($st->id, $approver);
        $this->assertSame(StocktakeStatus::PENDING_APPROVAL, $st->refresh()->status);
        $this->assertDomainError(ErrorCode::SOD_VIOLATION, fn () => $flow->approve($st->id, $counter));
        $flow->approve($st->id, $approver);
        $flow->post($st->id, $approver, $this->key());
        $this->assertSame('81.000000', $this->onHand($item, $wh));
    }

    public function test_found_stock_is_counted_as_an_unexpected_line_and_booked_in(): void
    {
        $wh = $this->site();
        $known = $this->item();
        $found = $this->item();
        $this->receive($known, $wh, '5');
        $flow = app(StocktakeWorkflow::class);
        $actor = $this->superAdmin();
        $st = $flow->save(['warehouse_id' => $wh->id], $actor);
        $flow->start($st->id, $actor);
        $line = StocktakeLine::query()->where('stocktake_id', $st->id)->firstOrFail();
        $flow->count($st->id, $actor, ['counts' => [['line_id' => $line->id, 'quantity' => '5']],
            'unexpected' => [['item_id' => $found->id, 'storage_location_id' => $wh->default_storage_location_id, 'quantity' => '3']]]);
        $flow->finishCounting($st->id, $actor);
        $this->assertSame(StocktakeStatus::RECOUNT_REQUIRED, $st->refresh()->status, 'found stock is verified by a recount');
        $foundLine = StocktakeLine::query()->where('stocktake_id', $st->id)->where('is_unexpected', true)->firstOrFail();
        $flow->count($st->id, $actor, ['counts' => [['line_id' => $foundLine->id, 'quantity' => '3']]]);
        $flow->finishCounting($st->id, $actor);
        $flow->review($st->id, $this->superAdmin());
        $other = $this->superAdmin();
        if ($st->refresh()->status === StocktakeStatus::PENDING_APPROVAL) {
            $flow->approve($st->id, $other);
        }
        $flow->post($st->id, $other, $this->key());
        $this->assertSame('3.000000', $this->onHand($found, $wh));
    }

    public function test_adjustment_requires_a_different_approver_and_cannot_remove_reserved_or_missing_stock(): void
    {
        $wh = $this->site();
        $item = $this->item();
        $lotItem = $this->item(TrackingMode::LOT);
        $this->receive($item, $wh, '10', '6');
        $flow = app(StockAdjustmentWorkflow::class);
        $clerk = $this->superAdmin();
        $manager = $this->superAdmin();

        $this->assertDomainError(ErrorCode::TRACKING_MODE_REQUIRES_LOT, fn () => $flow->save(['warehouse_id' => $wh->id, 'adjustment_type' => 'found', 'reason' => 'Found in aisle',
            'lines' => [['item_id' => $lotItem->id, 'storage_location_id' => $wh->default_storage_location_id, 'quantity' => '4']]], $clerk));

        $adj = $flow->save(['warehouse_id' => $wh->id, 'adjustment_type' => 'damage', 'reason' => 'Water damage in bay 3', 'lines' => [
            ['item_id' => $item->id, 'storage_location_id' => $wh->default_storage_location_id, 'quantity' => '-3'],
            ['item_id' => $lotItem->id, 'storage_location_id' => $wh->default_storage_location_id, 'quantity' => '4', 'lot_number' => 'FOUND-1',
                'expiry_date' => now()->addYear()->toDateString(), 'unit_cost' => '9'],
        ]], $clerk);
        $flow->submit($adj->id, $clerk);
        $this->assertDomainError(ErrorCode::SOD_VIOLATION, fn () => $flow->approve($adj->id, $clerk));
        $flow->approve($adj->id, $manager);
        $outcome = $flow->post($adj->id, $manager, $this->key());

        $this->assertCount(2, $outcome->body['movement_numbers'], 'decreases and increases post as separate movements');
        $this->assertSame('7.000000', $this->onHand($item, $wh));
        $this->assertSame('4.000000', $this->onHand($lotItem, $wh));
        $this->assertSame('18.0000', (string) $adj->refresh()->total_value, '−3 × 6 + 4 × 9');
        $this->assertNotNull(DB::table('inventory_lots')->where('item_id', $lotItem->id)->where('lot_number', 'FOUND-1')->value('id'));

        // A decrease beyond the eligible quantity is rejected at posting.
        $big = $flow->save(['warehouse_id' => $wh->id, 'adjustment_type' => 'loss', 'reason' => 'Theft', 'lines' => [
            ['item_id' => $item->id, 'storage_location_id' => $wh->default_storage_location_id, 'quantity' => '-8'],
        ]], $clerk);
        $flow->submit($big->id, $clerk);
        $flow->approve($big->id, $manager);
        $this->assertDomainError(ErrorCode::INSUFFICIENT_STOCK, fn () => $flow->post($big->id, $manager, $this->key()));
        $this->assertSame('7.000000', $this->onHand($item, $wh));
        $this->assertSame(StockCondition::AVAILABLE->value, DB::table('inventory_balances')->where('item_id', $item->id)->value('stock_condition'));
    }
}
