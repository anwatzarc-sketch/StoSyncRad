<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use App\Domains\Catalog\Enums\TrackingMode;
use App\Domains\Inventory\Enums\ReservationReferenceType;
use App\Domains\Inventory\Enums\StockCondition;
use App\Domains\Inventory\Posting\PositionKey;
use App\Domains\Inventory\Services\ReservationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\BuildsInventory;
use Tests\TestCase;

/**
 * Chapter 09 §9.6 / Chapter 15 §15.6 — real concurrent sessions (separate OS processes) racing on
 * the same limited stock. Data is committed (no wrapping transaction) because each worker has its
 * own MySQL session.
 */
final class ConcurrentPostingTest extends TestCase
{
    use BuildsInventory;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    public function test_two_concurrent_issues_of_70_cannot_overdraw_100(): void
    {
        $wh = $this->warehouse('CC'.Str::upper(Str::random(5)));
        $item = $this->item();
        $this->receive($item, $wh, '100');

        $results = $this->race(['op' => 'issue', 'item_id' => $item->id, 'warehouse_id' => $wh->id, 'qty' => '70'], 2);

        $this->assertExactlyOneWinner($results);
        $this->assertSame('30.000000', $this->onHand($item, $wh));
    }

    public function test_concurrent_issues_respect_active_reservations(): void
    {
        $wh = $this->warehouse('CR'.Str::upper(Str::random(5)));
        $item = $this->item();
        $this->receive($item, $wh, '100');
        DB::transaction(fn () => app(ReservationService::class)->reserve(ReservationReferenceType::STOCK_REQUEST, (string) Str::uuid7(), (string) Str::uuid7(),
            new PositionKey($item->id, null, $this->stockLocationId($wh), null, StockCondition::AVAILABLE), $wh->id, '20', null));

        $results = $this->race(['op' => 'issue', 'item_id' => $item->id, 'warehouse_id' => $wh->id, 'qty' => '50'], 2);

        $this->assertExactlyOneWinner($results);
        $balance = DB::table('inventory_balances')->where('item_id', $item->id)->where('warehouse_id', $wh->id)->first();
        $this->assertSame('50.000000', $balance->quantity_on_hand);
        $this->assertSame('20.000000', $balance->quantity_reserved);
    }

    public function test_concurrent_issues_on_a_lot_specific_position(): void
    {
        $wh = $this->warehouse('CL'.Str::upper(Str::random(5)));
        $item = $this->item(TrackingMode::LOT);
        $lot = $this->lot($item, 'L-'.Str::upper(Str::random(5)), now()->addYear()->toDateString());
        $this->receive($item, $wh, '100', lot: $lot);

        $results = $this->race(['op' => 'issue', 'item_id' => $item->id, 'warehouse_id' => $wh->id, 'qty' => '70', 'lot_id' => $lot->id], 2);

        $this->assertExactlyOneWinner($results);
        $this->assertSame('30.000000', $this->onHand($item, $wh, lot: $lot));
    }

    public function test_concurrent_dispatches_cannot_overdraw_the_source(): void
    {
        $src = $this->warehouse('CS'.Str::upper(Str::random(5)));
        $dst = $this->warehouse('CD'.Str::upper(Str::random(5)));
        $item = $this->item();
        $this->receive($item, $src, '100');

        $results = $this->race(['op' => 'dispatch', 'item_id' => $item->id, 'warehouse_id' => $src->id, 'to_warehouse_id' => $dst->id, 'qty' => '60'], 3);

        $this->assertExactlyOneWinner($results);
        $this->assertSame('40.000000', $this->onHand($item, $src));
        $inTransit = DB::table('inventory_balances as b')->join('inventory_locations as l', 'l.id', '=', 'b.inventory_location_id')
            ->where('b.item_id', $item->id)->where('l.location_type', 'in_transit')->sum('b.quantity_on_hand');
        $this->assertSame('60.000000', (string) $inTransit, 'dispatched quantity must be conserved in transit');
    }

    public function test_many_concurrent_small_issues_never_go_negative(): void
    {
        $wh = $this->warehouse('CM'.Str::upper(Str::random(5)));
        $item = $this->item();
        $this->receive($item, $wh, '25');

        $results = $this->race(['op' => 'issue', 'item_id' => $item->id, 'warehouse_id' => $wh->id, 'qty' => '10'], 6);

        $winners = array_filter($results, static fn (array $r) => $r['ok']);
        $this->assertCount(2, $winners, json_encode($results));
        foreach (array_filter($results, static fn (array $r) => ! $r['ok']) as $loser) {
            $this->assertSame('INSUFFICIENT_STOCK', $loser['code'], json_encode($loser));
        }
        $this->assertSame('5.000000', $this->onHand($item, $wh));
    }

    // -------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $args
     * @return array<int, array<string, mixed>>
     */
    private function race(array $args, int $workers): array
    {
        $args['start_at'] = microtime(true) + 2.5;   // all workers boot, then fire together
        $env = [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'stosyncrad_test', 'CACHE_STORE' => 'array',
            'NUMBERING_CONNECTION' => '', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array',
        ];
        $processes = [];
        for ($i = 0; $i < $workers; $i++) {
            $p = new Process([PHP_BINARY, base_path('tests/Concurrency/worker.php'), json_encode($args)], base_path(), $env, null, 60);
            $p->start();
            $processes[] = $p;
        }
        $results = [];
        foreach ($processes as $p) {
            $p->wait();
            $out = trim($p->getOutput());
            $decoded = json_decode(substr($out, (int) strrpos($out, '{')), true);
            $this->assertIsArray($decoded, "Worker output was not JSON:\n".$out."\n".$p->getErrorOutput());
            $results[] = $decoded;
        }

        return $results;
    }

    /** @param  array<int, array<string, mixed>>  $results */
    private function assertExactlyOneWinner(array $results): void
    {
        $winners = array_values(array_filter($results, static fn (array $r) => $r['ok']));
        $losers = array_values(array_filter($results, static fn (array $r) => ! $r['ok']));
        $this->assertCount(1, $winners, 'exactly one concurrent posting may succeed: '.json_encode($results));
        foreach ($losers as $loser) {
            $this->assertSame('INSUFFICIENT_STOCK', $loser['code'], 'losers must receive a controlled insufficient-stock response: '.json_encode($loser));
        }
    }
}
