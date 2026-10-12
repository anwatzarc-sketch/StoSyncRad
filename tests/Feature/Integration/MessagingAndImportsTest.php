<?php

declare(strict_types=1);

namespace Tests\Feature\Integration;

use App\Domains\Catalog\Models\Item;
use App\Domains\DataExchange\Enums\ImportBatchStatus;
use App\Domains\DataExchange\Enums\ImportType;
use App\Domains\DataExchange\Services\ImportService;
use App\Domains\Inventory\Enums\OpeningBalanceStatus;
use App\Domains\Inventory\Models\OpeningBalanceDocument;
use App\Domains\Messaging\Enums\WebhookDeliveryStatus;
use App\Domains\Messaging\Models\Notification;
use App\Domains\Messaging\Models\WebhookDelivery;
use App\Domains\Messaging\Services\OutboxRelay;
use App\Domains\Messaging\Services\WebhookService;
use App\Domains\Organization\Enums\BranchKind;
use App\Domains\Platform\Seeds\DocumentSequencesSeed;
use App\Domains\Platform\Seeds\SeedRunner;
use App\Domains\Platform\Seeds\SodRulesSeed;
use App\Domains\Transfers\Services\TransferWorkflow;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\BuildsActors;
use Tests\Support\BuildsInventory;
use Tests\TestCase;

/** Chapter 11 §11.7–11.9 — outbox → notifications/webhooks, CSV imports. */
final class MessagingAndImportsTest extends TestCase
{
    use BuildsActors;
    use BuildsInventory;

    protected function setUp(): void
    {
        parent::setUp();
        app(SeedRunner::class)->run(DocumentSequencesSeed::class);
        app(SeedRunner::class)->run(SodRulesSeed::class);
    }

    private function csv(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        $fh = fopen($path, 'wb');
        foreach ($rows as $r) {
            fputcsv($fh, $r, ',', '"', '');
        }
        fclose($fh);

        return $path;
    }

    public function test_outbox_events_notify_only_people_who_can_act_in_that_warehouse_and_feed_signed_webhooks(): void
    {
        DB::table('outbox_events')->update(['status' => 'processed']);   // isolate from other tests' events
        $source = $this->warehouse('NS'.Str::upper(Str::random(4)), $this->branch('BN'.Str::upper(Str::random(3)), BranchKind::HEADQUARTERS));
        $dest = $this->warehouse('ND'.Str::upper(Str::random(4)), $this->branch('BD'.Str::upper(Str::random(3)), BranchKind::RETAIL_STORE));
        $other = $this->warehouse('NO'.Str::upper(Str::random(4)));
        $item = $this->item();
        $approver = $this->actorWith(['transfers.transfer.approve'], 'warehouse', $source->id);
        $elsewhere = $this->actorWith(['transfers.transfer.approve'], 'warehouse', $other->id);

        $this->setConfig('integration.webhooks.enabled', true);
        $admin = $this->superAdmin();
        ['endpoint' => $endpoint, 'secret' => $secret] = app(WebhookService::class)->register('ERP', 'https://erp.example.test/hooks', ['transfers.*'], $admin);

        $flow = app(TransferWorkflow::class);
        $to = $flow->save(['source_warehouse_id' => $source->id, 'destination_warehouse_id' => $dest->id, 'lines' => [['item_id' => $item->id, 'quantity' => '2']]], $admin);
        $flow->submit($to->id, $admin);

        $stats = app(OutboxRelay::class)->relay();
        $this->assertGreaterThanOrEqual(1, $stats['processed']);
        $mine = Notification::query()->where('user_account_id', $approver->id)->get();
        $this->assertCount(1, $mine);
        $this->assertStringContainsString($to->document_number, $mine->first()->title);
        $this->assertSame(0, Notification::query()->where('user_account_id', $elsewhere->id)->count(), 'no notification outside the warehouse scope');
        $this->assertSame(0, app(OutboxRelay::class)->relay()['processed'], 'events are relayed once');

        Http::fake(['erp.example.test/*' => Http::sequence()->push('nope', 500)->push('ok', 200)]);
        $first = app(WebhookService::class)->deliverDue();
        $this->assertSame(1, $first['failed']);
        $delivery = WebhookDelivery::query()->where('webhook_endpoint_id', $endpoint->id)->firstOrFail();
        $this->assertSame(WebhookDeliveryStatus::FAILED, $delivery->status);
        $delivery->forceFill(['next_attempt_at' => now()->subSecond()])->save();
        $this->assertSame(1, app(WebhookService::class)->deliverDue()['delivered']);
        Http::assertSent(function (Request $request) use ($secret) {
            return WebhookService::verify($request->header('X-Webhook-Signature')[0] ?? null, $request->body(), $secret)
                && json_decode($request->body(), true)['type'] === 'transfers.transfer.submitted';
        });
        $this->assertFalse(WebhookService::verify('t='.time().',v1='.str_repeat('0', 64), '{}', $secret), 'forged signatures are rejected');
    }

    public function test_import_validates_rows_with_the_property_schemas_previews_and_imports_idempotently(): void
    {
        $admin = $this->superAdmin();
        $imports = app(ImportService::class);
        $rootCode = 'IMP'.Str::upper(Str::random(4));
        $path = $this->csv([
            ['code', 'name', 'parent_code', 'default_tracking_mode'],
            [$rootCode, 'Imported root', '', ''],
            [$rootCode.'-A', 'Leaf A', $rootCode, 'lot'],
            [$rootCode.'-B', 'Leaf B', $rootCode, 'not_a_mode'],
        ]);
        $batch = $imports->upload(ImportType::CATEGORIES, $path, 'categories.csv', $admin);
        $this->assertSame(ImportBatchStatus::PREVIEWED, $batch->status);
        $this->assertSame([3, 2, 1], [$batch->row_count, $batch->valid_count, $batch->invalid_count]);
        $this->assertSame($batch->id, $imports->upload(ImportType::CATEGORIES, $path, 'again.csv', $admin)->id, 're-upload of the same file is idempotent');

        // Rows are validated before the parent exists; processing order resolves them (root first).
        $done = $imports->confirm($batch->id, $admin)->refresh();
        $this->assertSame(ImportBatchStatus::COMPLETED_WITH_ERRORS, $done->status);
        $this->assertSame(2, $done->imported_count);
        $this->assertSame('Imported root / Leaf A', DB::table('item_categories')->where('code', $rootCode.'-A')->value('full_path'));
        $errors = $imports->errorFile($batch->id, $admin);
        $this->assertStringContainsString('not_a_mode', (string) file_get_contents(\Illuminate\Support\Facades\Storage::disk('private')->path($errors->path)));

        // Items by category code and unit code, with a generated SKU.
        $uom = $this->pce();
        $items = $imports->upload(ImportType::ITEMS, $this->csv([
            ['sku', 'name', 'category_code', 'base_uom_code', 'barcode', 'is_perishable'],
            ['', 'Imported widget', $rootCode.'-A', $uom->code, '590'.random_int(1000000000, 9999999999), 'yes'],
        ]), 'items.csv', $admin);
        $this->assertSame(1, $items->valid_count, json_encode(DB::table('import_rows')->where('import_batch_id', $items->id)->value('errors')));
        $imports->confirm($items->id, $admin);
        $item = Item::query()->where('name', 'Imported widget')->firstOrFail();
        $this->assertNotEmpty($item->sku);
        $this->assertTrue($item->is_perishable);
        $this->assertSame('lot', $item->tracking_mode->value, 'tracking mode defaults from the category');

        // A given SKU is the upsert key: unknown creates the item with that SKU, known updates it.
        $sku = 'IMP-'.Str::upper(Str::random(6));
        $named = $imports->upload(ImportType::ITEMS, $this->csv([
            ['sku', 'name', 'category_code', 'base_uom_code'],
            [$sku, 'Named widget', $rootCode.'-A', $uom->code],
        ]), 'named.csv', $admin);
        $this->assertSame(1, $named->valid_count, json_encode(DB::table('import_rows')->where('import_batch_id', $named->id)->value('errors')));
        $imports->confirm($named->id, $admin);
        $this->assertSame('Named widget', Item::query()->where('sku', $sku)->value('name'));
        $renamed = $imports->upload(ImportType::ITEMS, $this->csv([['sku', 'name', 'category_code'], [$sku, 'Renamed widget', $rootCode.'-A']]), 'renamed.csv', $admin);
        $imports->confirm($renamed->id, $admin);
        $this->assertSame(1, Item::query()->where('sku', $sku)->count());
        $this->assertSame('Renamed widget', Item::query()->where('sku', $sku)->value('name'));
    }

    public function test_opening_balance_import_creates_a_draft_document_and_never_posts_stock(): void
    {
        $admin = $this->superAdmin();
        $wh = $this->warehouse('OBI'.Str::upper(Str::random(3)));
        $item = $this->item();
        $location = DB::table('storage_locations')->where('id', $wh->default_storage_location_id)->value('full_path');
        $batch = app(ImportService::class)->upload(ImportType::OPENING_BALANCES, $this->csv([
            ['warehouse_code', 'cutoff_date', 'location_path', 'sku', 'quantity', 'unit_cost'],
            [$wh->code, now()->toDateString(), $location, $item->sku, '12', '3.5'],
            [$wh->code, now()->toDateString(), $location, $item->sku, '-1', '3.5'],
        ]), 'ob.csv', $admin);
        $this->assertSame(1, $batch->invalid_count, 'negative quantities are rejected by the line schema');
        app(ImportService::class)->confirm($batch->id, $admin);

        $doc = OpeningBalanceDocument::query()->where('warehouse_id', $wh->id)->firstOrFail();
        $this->assertSame(OpeningBalanceStatus::DRAFT, $doc->status);
        $this->assertSame(1, DB::table('opening_balance_lines')->where('document_id', $doc->id)->count());
        $this->assertSame('0', $this->onHand($item, $wh));
    }
}
