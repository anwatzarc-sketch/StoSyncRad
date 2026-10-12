<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domains\Catalog\Models\Item;
use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Enums\ReservationReferenceType;
use App\Domains\Inventory\Enums\StockCondition;
use App\Domains\Inventory\Models\StockReservation;
use App\Domains\Inventory\Posting\InventoryPoster;
use App\Domains\Inventory\Posting\MovementLine;
use App\Domains\Inventory\Posting\MovementRequest;
use App\Domains\Inventory\Posting\PositionKey;
use App\Domains\Inventory\Services\InventoryLocationResolver;
use App\Domains\Inventory\Services\ReservationService;
use App\Domains\Organization\Enums\DocumentType;
use App\Domains\Organization\Enums\InventoryLocationType;
use App\Domains\Organization\Models\Warehouse;
use App\Support\Testing\FaultInjector;
use App\Support\Testing\InjectedFault;
use App\Support\Transactions\PostingContext;
use App\Support\Transactions\PostingOutcome;
use App\Support\Transactions\PostingPipeline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsInventory;
use Tests\TestCase;

/**
 * Chapter 09 §9.12 / Chapter 15 §15.6 — a failure at any posting step leaves zero partial state,
 * and a retry with the same Idempotency-Key afterwards commits exactly once.
 */
final class AtomicityTest extends TestCase
{
    use BuildsInventory;

    protected function tearDown(): void
    {
        FaultInjector::reset();
        parent::tearDown();
    }

    /** @return array<string, array{0: string}> */
    public static function points(): array
    {
        return array_combine(FaultInjector::POINTS, array_map(static fn (string $p) => [$p], FaultInjector::POINTS));
    }

    #[DataProvider('points')]
    public function test_failure_at_each_posting_step_leaves_no_partial_state(string $point): void
    {
        $wh = $this->warehouse('AT'.Str::upper(Str::random(4)));
        $item = $this->item();
        $this->receive($item, $wh, '100');
        $reservation = $this->reserve($item, $wh, '30');
        $before = $this->snapshot($item, $reservation);
        $key = (string) Str::uuid7();
        $docId = (string) Str::uuid7();

        FaultInjector::arm($point);
        try {
            $this->issueConsuming($item, $wh, $reservation, $key, $docId);
            $this->fail("Fault at `$point` did not fire");
        } catch (InjectedFault) {
            // expected
        }

        $this->assertSame($before, $this->snapshot($item, $reservation), "Partial state leaked after a fault at `$point`");
        $this->assertNull(DB::table('idempotency_keys')->where('key', $key)->first(), 'idempotency key must roll back');
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'test.issue.posted')->where('subject_id', $docId)->count());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'test.issue.failed')->where('subject_id', $docId)
            ->where('is_failed_attempt', true)->count(), 'a failed-attempt record must be written outside the rolled-back transaction');

        // Retry with the same key now succeeds exactly once, then replays.
        FaultInjector::reset();
        $first = $this->issueConsuming($item, $wh, $reservation, $key, $docId);
        $second = $this->issueConsuming($item, $wh, $reservation, $key, $docId);
        $this->assertFalse($first->replayed);
        $this->assertTrue($second->replayed);
        $this->assertSame('70.000000', $this->onHand($item, $wh));
        $this->assertSame(1, DB::table('stock_movements')->where('source_document_id', $docId)->count());
        $this->assertSame('consumed', StockReservation::query()->find($reservation->id)->status->value);
    }

    private function reserve(Item $item, Warehouse $wh, string $qty): StockReservation
    {
        $key = new PositionKey($item->id, null, $this->stockLocationId($wh), null, StockCondition::AVAILABLE);

        return DB::transaction(fn () => app(ReservationService::class)->reserve(
            ReservationReferenceType::STOCK_REQUEST, (string) Str::uuid7(), (string) Str::uuid7(), $key, $wh->id, $qty, null));
    }

    private function issueConsuming(Item $item, Warehouse $wh, StockReservation $reservation, string $key, string $docId): PostingOutcome
    {
        $customer = app(InventoryLocationResolver::class)->virtual(InventoryLocationType::CUSTOMER)->id;

        return app(PostingPipeline::class)->run(null, 'test.issue', $key, ['doc' => $docId],
            function (PostingContext $ctx) use ($item, $wh, $reservation, $customer, $docId) {
                $movement = app(InventoryPoster::class)->post(new MovementRequest(MovementType::ISSUE, DocumentType::STOCK_ISSUE, $docId, 'ISS-T', $docId, [
                    new MovementLine($item->id, null, $this->stockLocationId($wh), StockCondition::AVAILABLE, $customer, StockCondition::AVAILABLE, '30',
                        consumeReservations: [$reservation->id => '30']),
                ], warehouseId: $wh->id), $ctx);
                $ctx->audit('test.issue.posted', 'stock_issue', $docId, null, ['movement_id' => $movement->id]);

                return new PostingOutcome(['movement_id' => $movement->id], 201, DocumentType::STOCK_ISSUE->value, $docId);
            }, failureAudit: ['test.issue.failed', 'stock_issue', $docId]);
    }

    /** @return array<string, mixed> */
    private function snapshot(Item $item, StockReservation $reservation): array
    {
        return [
            'movements' => DB::table('stock_movements')->count(),
            'lines' => DB::table('stock_movement_lines')->where('item_id', $item->id)->count(),
            'balances' => DB::table('inventory_balances')->where('item_id', $item->id)->orderBy('id')
                ->get(['id', 'quantity_on_hand', 'quantity_reserved', 'version'])->map(fn ($r) => (array) $r)->all(),
            'reservation' => (array) DB::table('stock_reservations')->where('id', $reservation->id)->first(['quantity_consumed', 'status', 'version']),
            'outbox' => DB::table('outbox_events')->count(),
            'projection' => DB::table('item_cost_projections')->where('item_id', $item->id)->value('quantity'),
        ];
    }
}
