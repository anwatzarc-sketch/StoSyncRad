<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domains\Catalog\Enums\CostingMethod;
use App\Domains\Catalog\Enums\TrackingMode;
use App\Domains\Inventory\Enums\LotQcStatus;
use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Enums\ReservationReferenceType;
use App\Domains\Inventory\Enums\SerialStatus;
use App\Domains\Inventory\Enums\StockCondition;
use App\Domains\Inventory\Models\StockMovement;
use App\Domains\Inventory\Posting\InventoryPoster;
use App\Domains\Inventory\Posting\MovementLine;
use App\Domains\Inventory\Posting\MovementRequest;
use App\Domains\Inventory\Posting\PositionKey;
use App\Domains\Inventory\Services\InventoryLocationResolver;
use App\Domains\Inventory\Services\LotService;
use App\Domains\Inventory\Services\ReservationService;
use App\Domains\Organization\Enums\DocumentType;
use App\Domains\Organization\Enums\InventoryLocationType;
use App\Support\Database\SqlError;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Transactions\PostingContext;
use App\Support\Transactions\PostingOutcome;
use App\Support\Transactions\PostingPipeline;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsInventory;
use Tests\TestCase;

final class InventoryPosterTest extends TestCase
{
    use BuildsInventory;

    public function test_receipt_increases_the_position_and_average_cost(): void
    {
        $wh = $this->warehouse('WHA');
        $item = $this->item();

        $this->receive($item, $wh, '10', '12.5');

        $this->assertSame('10.000000', $this->onHand($item, $wh));
        $this->assertSame('12.50000000', DB::table('items')->where('id', $item->id)->value('current_average_cost'));
        $line = DB::table('stock_movement_lines')->where('item_id', $item->id)->first();
        $this->assertSame('12.50000000', $line->unit_cost_snapshot);
        $this->assertSame('125.0000', $line->total_cost_snapshot);
    }

    public function test_moving_average_is_recomputed_and_used_for_issues(): void
    {
        $wh = $this->warehouse('WHB');
        $item = $this->item();
        $this->receive($item, $wh, '10', '10');
        $this->receive($item, $wh, '10', '20');
        $this->issue($item, $wh, '5');

        $this->assertSame('15.000000', $this->onHand($item, $wh));
        $issueLine = DB::table('stock_movement_lines as l')->join('stock_movements as m', 'm.id', '=', 'l.stock_movement_id')
            ->where('m.movement_type', MovementType::ISSUE->value)->where('l.item_id', $item->id)->first(['l.*']);
        $this->assertSame('15.00000000', $issueLine->unit_cost_snapshot);
    }

    public function test_fifo_consumes_oldest_layers_first(): void
    {
        $wh = $this->warehouse('WHC');
        $item = $this->item(costing: CostingMethod::FIFO);
        $this->receive($item, $wh, '10', '10');
        $this->receive($item, $wh, '10', '20');
        $this->issue($item, $wh, '15');

        $issueLine = DB::table('stock_movement_lines as l')->join('stock_movements as m', 'm.id', '=', 'l.stock_movement_id')
            ->where('m.movement_type', MovementType::ISSUE->value)->where('l.item_id', $item->id)->first(['l.*']);
        // (10 × 10 + 5 × 20) / 15
        $this->assertSame('13.33333333', $issueLine->unit_cost_snapshot);
        $remaining = DB::table('valuation_layers')->where('item_id', $item->id)->orderBy('layer_date')->orderBy('id')->pluck('remaining_quantity')->all();
        $this->assertSame(['0.000000', '5.000000'], $remaining);
    }

    public function test_issue_beyond_availability_is_rejected_and_leaves_no_partial_state(): void
    {
        $wh = $this->warehouse('WHD');
        $item = $this->item();
        $this->receive($item, $wh, '100');
        $movementsBefore = StockMovement::query()->count();
        $outboxBefore = DB::table('outbox_events')->count();

        try {
            $this->issue($item, $wh, '100.000001');
            $this->fail('Expected INSUFFICIENT_STOCK');
        } catch (DomainException $e) {
            $this->assertSame(ErrorCode::INSUFFICIENT_STOCK, $e->errorCode);
            $this->assertSame('100', $e->details['available_quantity']);
        }

        $this->assertSame($movementsBefore, StockMovement::query()->count());
        $this->assertSame($outboxBefore, DB::table('outbox_events')->count());
        $this->assertSame('100.000000', $this->onHand($item, $wh));
    }

    public function test_repeated_request_with_same_key_is_replayed_without_duplicating_movements(): void
    {
        $wh = $this->warehouse('WHE');
        $item = $this->item();
        $this->receive($item, $wh, '50');
        $key = (string) Str::uuid7();

        $customer = app(InventoryLocationResolver::class)->virtual(InventoryLocationType::CUSTOMER)->id;
        $lines = [new MovementLine($item->id, null, $this->stockLocationId($wh), StockCondition::AVAILABLE, $customer, StockCondition::AVAILABLE, '5')];
        $first = $this->postWithFixedDocument($key, $wh, $lines, 'DOC-A');
        $second = $this->postWithFixedDocument($key, $wh, $lines, 'DOC-A');

        $this->assertFalse($first->replayed);
        $this->assertTrue($second->replayed);
        $this->assertSame($first->body, $second->body);
        $this->assertSame('45.000000', $this->onHand($item, $wh));

        // Same key, different payload → rejected, nothing posted.
        $this->assertDomainError(ErrorCode::IDEMPOTENCY_PAYLOAD_MISMATCH, fn () => $this->postWithFixedDocument($key, $wh, $lines, 'DOC-B'));
        $this->assertSame('45.000000', $this->onHand($item, $wh));
    }

    public function test_lot_tracked_items_require_a_lot(): void
    {
        $wh = $this->warehouse('WHF');
        $item = $this->item(TrackingMode::LOT);

        $this->assertDomainError(ErrorCode::TRACKING_MODE_REQUIRES_LOT, fn () => $this->receive($item, $wh, '5'));
    }

    public function test_expired_and_unreleased_lots_cannot_be_issued(): void
    {
        $wh = $this->warehouse('WHG');
        $item = $this->item(TrackingMode::LOT);
        $expired = $this->lot($item, 'EXP-1', now()->subDay()->toDateString());
        $pending = $this->lot($item, 'PEND-1', now()->addYear()->toDateString(), LotQcStatus::PENDING);
        $this->receive($item, $wh, '5', lot: $expired);
        $this->receive($item, $wh, '5', lot: $pending);

        $this->assertDomainError(ErrorCode::STOCK_INELIGIBLE, fn () => $this->issue($item, $wh, '1', $expired));
        $this->assertDomainError(ErrorCode::LOT_NOT_RELEASED, fn () => $this->issue($item, $wh, '1', $pending));
    }

    public function test_serial_items_move_one_identified_unit_at_a_time(): void
    {
        $wh = $this->warehouse('WHH');
        $item = $this->item(TrackingMode::SERIAL);
        $serial = app(LotService::class)->resolveSerial($item, null, 'SN-0001', null);

        $this->receive($item, $wh, '1', serialId: $serial->id);
        $serial->refresh();
        $this->assertSame(SerialStatus::IN_STOCK, $serial->lifecycle_status);
        $this->assertSame($this->stockLocationId($wh), $serial->current_inventory_location_id);

        // Receiving the same serial again is rejected: a serial cannot be in two places.
        $this->assertDomainError(ErrorCode::SERIAL_STATE_INVALID, fn () => $this->receive($item, $wh, '1', serialId: $serial->id));
        // Quantity must be exactly one.
        $this->assertDomainError(ErrorCode::TRACKING_MODE_REQUIRES_SERIAL, fn () => $this->receive($item, $wh, '2', serialId: $serial->id));
    }

    public function test_stock_in_transit_can_only_leave_through_a_transfer_receipt(): void
    {
        $src = $this->warehouse('WHI');
        $dst = $this->warehouse('WHJ');
        $item = $this->item();
        $this->receive($item, $src, '10');
        $transit = app(InventoryLocationResolver::class)->inTransit($src->id, $dst->id)->id;

        $this->postMovement(MovementType::TRANSFER_DISPATCH, DocumentType::TRANSFER_DISPATCH, $src, [
            new MovementLine($item->id, null, $this->stockLocationId($src), StockCondition::AVAILABLE, $transit, StockCondition::AVAILABLE, '4'),
        ]);
        $customer = app(InventoryLocationResolver::class)->virtual(InventoryLocationType::CUSTOMER)->id;

        $this->assertDomainError(ErrorCode::LOT_LOCKED, fn () => $this->postMovement(MovementType::ISSUE, DocumentType::STOCK_ISSUE, $src, [
            new MovementLine($item->id, null, $transit, StockCondition::AVAILABLE, $customer, StockCondition::AVAILABLE, '1'),
        ]));

        $this->postMovement(MovementType::TRANSFER_RECEIPT, DocumentType::TRANSFER_RECEIPT, $dst, [
            new MovementLine($item->id, null, $transit, StockCondition::AVAILABLE, $this->stockLocationId($dst), StockCondition::AVAILABLE, '4'),
        ]);
        $this->assertSame('6.000000', $this->onHand($item, $src));
        $this->assertSame('4.000000', $this->onHand($item, $dst));
        $this->assertSame('0.000000', (string) DB::table('inventory_balances')->where('inventory_location_id', $transit)->sum('quantity_on_hand'));
    }

    public function test_reservations_reduce_availability_and_are_consumed_by_issues(): void
    {
        $wh = $this->warehouse('WHK');
        $item = $this->item();
        $this->receive($item, $wh, '10');
        $key = new PositionKey($item->id, null, $this->stockLocationId($wh), null, StockCondition::AVAILABLE);
        $lineId = (string) Str::uuid7();

        $reservation = DB::transaction(fn () => app(ReservationService::class)->reserve(
            ReservationReferenceType::STOCK_REQUEST, (string) Str::uuid7(), $lineId, $key, $wh->id, '8', null));

        // Only 2 remain unreserved.
        $this->assertDomainError(ErrorCode::INSUFFICIENT_STOCK, fn () => $this->issue($item, $wh, '3'));

        // An issue consuming the reservation may take the reserved stock.
        $this->issue($item, $wh, '8', consume: [$reservation->id => '8']);
        $reservation->refresh();
        $this->assertSame('consumed', $reservation->status->value);
        $balance = DB::table('inventory_balances')->where('id', $reservation->inventory_balance_id)->first();
        $this->assertSame('2.000000', $balance->quantity_on_hand);
        $this->assertSame('0.000000', $balance->quantity_reserved);
    }

    public function test_negative_stock_requires_an_explicit_override_and_is_guarded_by_the_database(): void
    {
        $wh = $this->warehouse('WHL');
        $item = $this->item();
        $customer = app(InventoryLocationResolver::class)->virtual(InventoryLocationType::CUSTOMER)->id;

        $this->postMovement(MovementType::ISSUE, DocumentType::STOCK_ISSUE, $wh, [
            new MovementLine($item->id, null, $this->stockLocationId($wh), StockCondition::AVAILABLE, $customer, StockCondition::AVAILABLE, '3', allowNegative: true),
        ]);
        $balance = DB::table('inventory_balances')->where('item_id', $item->id)->where('warehouse_id', $wh->id)->first();
        $this->assertSame('-3.000000', $balance->quantity_on_hand);
        $this->assertTrue((bool) $balance->allow_negative);

        // The CHECK constraint rejects negative balances that were not explicitly allowed.
        $this->expectException(QueryException::class);
        DB::table('inventory_balances')->where('id', $balance->id)->update(['allow_negative' => false]);
    }

    public function test_ledger_rows_are_append_only_for_the_runtime_role(): void
    {
        $wh = $this->warehouse('WHM');
        $item = $this->item();
        $this->receive($item, $wh, '1');
        $lineId = DB::table('stock_movement_lines')->where('item_id', $item->id)->value('id');

        // MySQL refuses these before executing them (no privilege), so the surrounding transaction is unaffected.
        try {
            DB::table('stock_movement_lines')->where('id', $lineId)->update(['quantity' => '999']);
            $this->fail('Expected the ledger update to be rejected');
        } catch (QueryException $e) {
            $this->assertSame(SqlError::COLUMN_ACCESS_DENIED, $e->errorInfo[1], 'runtime account must lack UPDATE on ledger quantity');
        }
        try {
            DB::table('stock_movement_lines')->where('id', $lineId)->delete();
            $this->fail('Expected the ledger delete to be rejected');
        } catch (QueryException $e) {
            $this->assertSame(SqlError::TABLE_ACCESS_DENIED, $e->errorInfo[1], 'runtime account must lack DELETE on the ledger');
        }
        $this->assertSame('1.000000', DB::table('stock_movement_lines')->where('id', $lineId)->value('quantity'));
    }

    public function test_balances_reconcile_to_the_ledger_after_a_mix_of_movements(): void
    {
        $a = $this->warehouse('WHN');
        $b = $this->warehouse('WHO');
        $item = $this->item();
        $this->receive($item, $a, '30', '5');
        $this->issue($item, $a, '7');
        $transit = app(InventoryLocationResolver::class)->inTransit($a->id, $b->id)->id;
        $this->postMovement(MovementType::TRANSFER_DISPATCH, DocumentType::TRANSFER_DISPATCH, $a, [
            new MovementLine($item->id, null, $this->stockLocationId($a), StockCondition::AVAILABLE, $transit, StockCondition::AVAILABLE, '10'),
        ]);
        $this->postMovement(MovementType::TRANSFER_RECEIPT, DocumentType::TRANSFER_RECEIPT, $b, [
            new MovementLine($item->id, null, $transit, StockCondition::AVAILABLE, $this->stockLocationId($b), StockCondition::AVAILABLE, '6'),
        ]);

        $ledger = DB::select(<<<'SQL'
            SELECT loc, cond, SUM(q) AS qty FROM (
                SELECT destination_inventory_location_id AS loc, destination_condition AS cond, quantity AS q FROM stock_movement_lines WHERE item_id = ?
                UNION ALL
                SELECT source_inventory_location_id, source_condition, -quantity FROM stock_movement_lines WHERE item_id = ?
            ) x GROUP BY loc, cond
        SQL, [$item->id, $item->id]);
        foreach ($ledger as $row) {
            $projection = DB::table('inventory_balances')->where('item_id', $item->id)->where('inventory_location_id', $row->loc)
                ->where('stock_condition', $row->cond)->value('quantity_on_hand');
            if ($projection === null) {
                continue; // external (virtual) locations hold no balance
            }
            $this->assertSame((string) $row->qty, $projection, "Projection mismatch at location {$row->loc}");
        }
        $this->assertSame('13.000000', $this->onHand($item, $a));
        $this->assertSame('6.000000', $this->onHand($item, $b));
    }

    // ---------------------------------------------------------------------------------------

    /** @param  array<int, MovementLine>  $lines */
    private function postWithFixedDocument(string $key, $wh, array $lines, string $docRef)
    {
        return app(PostingPipeline::class)->run(null, 'test.issue', $key, ['doc' => $docRef],
            function (PostingContext $ctx) use ($wh, $lines) {
                $docId = (string) Str::uuid7();
                $m = app(InventoryPoster::class)->post(new MovementRequest(
                    MovementType::ISSUE, DocumentType::STOCK_ISSUE, $docId, 'T', $docId, $lines, warehouseId: $wh->id), $ctx);

                return new PostingOutcome(['movement_id' => $m->id], 201);
            });
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
}
