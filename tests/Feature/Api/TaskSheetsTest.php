<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domains\Catalog\Enums\TrackingMode;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Organization\Actions\CreateBranchAction;
use App\Domains\Organization\Enums\BranchKind;
use App\Domains\Platform\Enums\PlatformSetupState;
use App\Domains\Platform\Seeds\DocumentSequencesSeed;
use App\Domains\Platform\Seeds\SeedRunner;
use App\Domains\Platform\Seeds\SodRulesSeed;
use App\Domains\Platform\Services\SetupState;
use App\Domains\Transfers\Enums\TransferStatus;
use App\Domains\Transfers\Models\TransferOrder;
use App\Domains\Transfers\Services\TransferWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsActors;
use Tests\Support\BuildsInventory;
use Tests\TestCase;

/** Chapter 12 §12.6 — floor task sheets drive real postings without anyone typing ids. */
final class TaskSheetsTest extends TestCase
{
    use BuildsActors;
    use BuildsInventory;

    protected function setUp(): void
    {
        parent::setUp();
        app(SeedRunner::class)->run(DocumentSequencesSeed::class);
        app(SeedRunner::class)->run(SodRulesSeed::class);
        DB::table('platform_setup')->delete();
        DB::table('platform_setup')->insert(['id' => (string) Str::uuid7(), 'deployment_id' => (string) Str::uuid7(), 'state' => PlatformSetupState::COMPLETED->value,
            'completed_steps' => '[]', 'completed_at' => now(), 'locked_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        app(SetupState::class)->refresh();
    }

    private function as(UserAccount $account): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($account->createToken('t')->plainTextToken);
    }

    public function test_dispatch_and_receipt_sheets_round_trip_a_batch_tracked_transfer(): void
    {
        $hq = $this->warehouse('HQW'.Str::upper(Str::random(3)), app(CreateBranchAction::class)->execute(['code' => 'HQ'.Str::upper(Str::random(3)), 'name' => 'HQ',
            'kind' => BranchKind::HEADQUARTERS->value], null));
        $store = $this->warehouse('STW'.Str::upper(Str::random(3)), app(CreateBranchAction::class)->execute(['code' => 'ST'.Str::upper(Str::random(3)), 'name' => 'Store',
            'kind' => BranchKind::RETAIL_STORE->value], null));
        $item = $this->item(TrackingMode::LOT);
        $soon = $this->lot($item, 'LOT-SOON', now()->addMonths(2)->toDateString());
        $later = $this->lot($item, 'LOT-LATER', now()->addYear()->toDateString());
        $this->receive($item, $hq, '30', '8', $later);
        $this->receive($item, $hq, '30', '8', $soon);

        $flow = app(TransferWorkflow::class);
        $requester = $this->superAdmin();
        $to = $flow->save(['source_warehouse_id' => $hq->id, 'destination_warehouse_id' => $store->id, 'lines' => [['item_id' => $item->id, 'quantity' => '40']]], $requester);
        $flow->submit($to->id, $requester);
        $flow->approve($to->id, $this->superAdmin());

        $dispatcher = $this->actorWith(['transfers.transfer.read', 'transfers.transfer.dispatch'], 'warehouse', $hq->id);
        $sheet = $this->as($dispatcher)->getJson("/api/v1/transfers/{$to->id}/dispatch-sheet")->assertOk()->json('data');
        $line = $sheet['lines'][0];
        $this->assertSame($item->sku, $line['sku']);
        $this->assertEquals(40, (float) $line['outstanding']);
        $this->assertEquals(40, array_sum(array_map(fn ($x) => (float) $x['take'], $line['suggestions'])), 'suggestions cover what is left to dispatch');
        $this->assertTrue($line['suggestions'][0]['reserved'], 'positions reserved at approval come first');
        $this->assertEmpty(array_diff(array_column($line['suggestions'], 'lot_number'), ['LOT-SOON', 'LOT-LATER']));

        $lines = array_map(fn ($s) => ['transfer_order_line_id' => $line['id'], 'storage_location_id' => $s['storage_location_id'],
            'inventory_lot_id' => $s['lot_id'], 'quantity' => (string) $s['take']], $line['suggestions']);
        $this->as($dispatcher)->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/transfers/{$to->id}/dispatch", ['lines' => $lines, 'version' => $sheet['transfer']['version']])->assertSuccessful();
        $this->assertSame(TransferStatus::IN_TRANSIT, TransferOrder::query()->find($to->id)->status);

        $this->as($dispatcher)->getJson("/api/v1/transfers/{$to->id}/receipt-sheet")->assertStatus(403);
        $receiver = $this->actorWith(['transfers.transfer.read', 'transfers.transfer.receive'], 'warehouse', $store->id);
        $receipt = $this->as($receiver)->getJson("/api/v1/transfers/{$to->id}/receipt-sheet")->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(array_values(array_unique(array_column($line['suggestions'], 'lot_number'))), array_values(array_unique(array_column($receipt['lines'], 'lot_number'))));
        $this->assertEquals(40, array_sum(array_map(fn ($l) => (float) $l['outstanding'], $receipt['lines'])));

        $received = array_map(fn ($l) => ['transfer_dispatch_line_id' => $l['id'], 'accepted_quantity' => $l['outstanding'], 'scanned_lot_number' => $l['lot_number']], $receipt['lines']);
        $version = TransferOrder::query()->find($to->id)->version;
        $this->as($receiver)->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/transfers/{$to->id}/receive", ['lines' => $received, 'version' => $version])->assertSuccessful();
        $this->assertSame([], $this->as($receiver)->getJson("/api/v1/transfers/{$to->id}/receipt-sheet")->json('data.lines'));
        $this->assertSame('40.000000', $this->onHand($item, $store));
    }

    public function test_blind_count_sheet_hides_book_quantities_from_counters(): void
    {
        $wh = $this->warehouse('CNT'.Str::upper(Str::random(3)));
        $item = $this->item();
        $this->receive($item, $wh, '12', '5');
        $admin = $this->superAdmin();
        $flow = app(\App\Domains\Stocktaking\Services\StocktakeWorkflow::class);
        $st = $flow->save(['warehouse_id' => $wh->id, 'stocktake_type' => 'full', 'is_blind' => true], $admin);
        $flow->schedule($st->id, $admin);
        $flow->start($st->id, $admin);

        $counter = $this->actorWith(['stocktaking.stocktake.read', 'stocktaking.stocktake.count'], 'warehouse', $wh->id);
        $sheet = $this->as($counter)->getJson("/api/v1/stocktakes/{$st->id}/count-sheet")->assertOk()->json('data');
        $this->assertFalse($sheet['stocktake']['show_book_quantity']);
        $this->assertArrayNotHasKey('snapshot_quantity', $sheet['lines'][0]);
        $this->assertSame($item->sku, $sheet['lines'][0]['sku']);

        $supervisor = $this->actorWith(['stocktaking.stocktake.count', 'stocktaking.stocktake.view_book_qty'], 'warehouse', $wh->id);
        $this->assertEquals(12, (float) $this->as($supervisor)->getJson("/api/v1/stocktakes/{$st->id}/count-sheet")->json('data.lines.0.snapshot_quantity'));
    }
}
