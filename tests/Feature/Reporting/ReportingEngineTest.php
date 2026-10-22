<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Domains\Catalog\Enums\TrackingMode;
use App\Domains\Platform\Seeds\SeedRunner;
use App\Domains\Platform\Seeds\StandardReportsSeed;
use App\Domains\ReportingEngine\Enums\ReportOutputFormat;
use App\Domains\ReportingEngine\Enums\ReportRunStatus;
use App\Domains\ReportingEngine\Enums\ReportVisibility;
use App\Domains\ReportingEngine\Models\ReportDefinition;
use App\Domains\ReportingEngine\Services\ReportDefinitionService;
use App\Domains\ReportingEngine\Services\ReportingSetup;
use App\Domains\ReportingEngine\Services\ReportRunner;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\BuildsActors;
use Tests\Support\BuildsInventory;
use Tests\TestCase;

/**
 * Chapter 10 §10.9 — isolation guarantees, live on-demand data, exports, definitions and the
 * shipped standard reports. Reporting runs read through the separate read-only role, so this class
 * commits its fixtures and asks for a fresh schema afterwards.
 */
final class ReportingEngineTest extends TestCase
{
    use BuildsActors;
    use BuildsInventory;

    protected $connectionsToTransact = [];

    public static function tearDownAfterClass(): void
    {
        self::rebuildSchemaAfterwards();
        parent::tearDownAfterClass();
    }

    private function runner(): ReportRunner
    {
        return app(ReportRunner::class);
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

    public function test_reporting_role_is_read_only_and_every_standard_report_runs_on_live_data(): void
    {
        $setup = app(ReportingSetup::class);
        $probe = $setup->probeReadOnly();
        $this->assertTrue($probe['read_only'], json_encode($probe));
        $this->assertFalse($probe['can_read_base_tables']);
        $this->assertSame(50, $setup->syncDatasets()['datasets']);
        $seeded = app(SeedRunner::class)->run(StandardReportsSeed::class);
        $this->assertSame(count(StandardReportsSeed::data()), $seeded['total']);

        $wh = $this->warehouse('RPT'.Str::upper(Str::random(4)));
        $item = $this->item(TrackingMode::LOT);
        $lot = $this->lot($item, 'RL-'.Str::upper(Str::random(4)), now()->addDays(10)->toDateString());
        $this->receive($item, $wh, '40', '2.5', $lot);
        $admin = $this->superAdmin();
        $this->setConfig('feature.retail_sales', true);
        $this->setConfig('feature.ecommerce_fulfillment', true);

        foreach (ReportDefinition::query()->where('is_system_seed', true)->get() as $def) {
            $result = $this->runner()->runWeb($def, $admin, StandardReportsSeed::sampleParams($def->definition) + ['sku' => $item->sku,
                'from' => now()->subDay()->toDateString(), 'to' => now()->addDay()->toDateString()]);
            $this->assertArrayHasKey('rows', $result, $def->code);
        }

        $expiring = ReportDefinition::query()->where('code', 'std_expiring_stock')->firstOrFail();
        $rows = collect($this->runner()->runWeb($expiring, $admin)['rows'])->where('item_sku', $item->sku);
        $this->assertSame('40.000000', (string) $rows->first()['quantity_on_hand']);

        // Live: every run queries current data.
        $this->issue($item, $wh, '15', $lot);
        $rows = collect($this->runner()->runWeb($expiring, $admin)['rows'])->where('item_sku', $item->sku);
        $this->assertSame('25.000000', (string) $rows->first()['quantity_on_hand']);
    }

    public function test_warehouse_scope_cannot_be_bypassed_via_joins_or_aggregates_and_restricted_fields_never_leak(): void
    {
        $a = $this->warehouse('SA'.Str::upper(Str::random(4)));
        $b = $this->warehouse('SB'.Str::upper(Str::random(4)));
        $item = $this->item();
        $this->receive($item, $a, '7', '3');
        $this->receive($item, $b, '11', '3');
        $clerk = $this->actorWith(['inventory.stock.read', 'inventory.movement.read', 'reporting.report.run', 'catalog.item.read'], 'warehouse', $a->id);

        $rows = $this->runner()->runWeb(['dataset_code' => 'stock_positions', 'definition' => ['fields' => ['warehouse_code', 'item_sku', 'quantity_on_hand'],
            'filters' => ['op' => 'and', 'conditions' => [['field' => 'item_id', 'operator' => 'eq', 'value' => $item->id]]]]], $clerk)['rows'];
        $this->assertSame([$a->code], array_values(array_unique(array_column($rows, 'warehouse_code'))));

        $total = $this->runner()->runWeb(['dataset_code' => 'stock_positions', 'definition' => ['group_by' => ['item_sku'],
            'aggregates' => [['fn' => 'sum', 'field' => 'quantity_on_hand', 'as' => 'qty']],
            'filters' => ['op' => 'and', 'conditions' => [['field' => 'item_id', 'operator' => 'eq', 'value' => $item->id]]]]], $clerk)['rows'];
        $this->assertSame('7.000000', (string) $total[0]['qty'], 'aggregates only see the authorized warehouse');

        // Lines joined to their movement header: still only lines touching warehouse A.
        $lines = $this->runner()->runWeb(['dataset_code' => 'stock_movement_lines', 'definition' => ['joins' => ['movement'],
            'fields' => ['destination_warehouse_code', 'movement.warehouse_code', 'quantity'],
            'filters' => ['op' => 'and', 'conditions' => [['field' => 'item_id', 'operator' => 'eq', 'value' => $item->id]]]]], $clerk)['rows'];
        $this->assertSame([$a->code], array_values(array_unique(array_column($lines, 'destination_warehouse_code'))));

        $this->assertDomainError(ErrorCode::REPORT_FIELD_FORBIDDEN, fn () => $this->runner()->runWeb(['dataset_code' => 'stock_positions',
            'definition' => ['fields' => ['item_sku', 'total_value']]], $clerk));
        $this->assertDomainError(ErrorCode::REPORT_FIELD_FORBIDDEN, fn () => $this->runner()->runWeb(['dataset_code' => 'stock_positions',
            'definition' => ['group_by' => ['item_sku'], 'aggregates' => [['fn' => 'sum', 'expr' => ['fn' => 'mul', 'args' => [['field' => 'unit_cost'], ['value' => 1]]], 'as' => 'v']]]], $clerk));
        $this->assertDomainError(ErrorCode::PERMISSION_DENIED, fn () => $this->runner()->runWeb(['dataset_code' => 'purchase_orders', 'definition' => []], $clerk));
    }

    public function test_injection_strings_are_values_and_invalid_definitions_are_rejected(): void
    {
        $admin = $this->superAdmin();
        $evil = "x'; DROP TABLE items; --";
        $result = $this->runner()->runWeb(['dataset_code' => 'items', 'definition' => ['fields' => ['sku'],
            'filters' => ['op' => 'or', 'conditions' => [['field' => 'name', 'operator' => 'contains', 'value' => $evil], ['field' => 'sku', 'operator' => 'eq', 'value' => $evil]]]]], $admin);
        $this->assertSame([], $result['rows']);
        $this->assertTrue(DB::table('items')->exists() || DB::table('items')->count() === 0, 'items table still exists');

        foreach ([
            ['fields' => ['sku; DROP TABLE x']],
            ['joins' => ['no_such_relationship']],
            ['fields' => [['expr' => ['fn' => 'sleep', 'args' => [['value' => 10]]], 'as' => 'zz']]],
            ['fields' => ['sku'], 'filters' => ['op' => 'and', 'conditions' => [['field' => 'status', 'operator' => 'eq', 'value' => 'not_a_status']]]],
            ['fields' => ['sku'], 'filters' => ['op' => 'and', 'conditions' => [['field' => 'sku', 'operator' => 'gt', 'value' => 'a']]]],
            ['fields' => ['sku'], 'aggregates' => [['fn' => 'sum', 'field' => 'standard_cost']]],
        ] as $bad) {
            $this->assertDomainError(ErrorCode::REPORT_DEFINITION_INVALID, fn () => $this->runner()->runWeb(['dataset_code' => 'items', 'definition' => $bad], $admin));
        }
    }

    public function test_exports_stream_to_private_expiring_files_with_reauthorized_download(): void
    {
        Queue::fake();
        $wh = $this->warehouse('EXP'.Str::upper(Str::random(4)));
        $item = $this->item();
        $this->receive($item, $wh, '3', '4');
        $admin = $this->superAdmin();
        $definition = ['dataset_code' => 'stock_positions', 'definition' => ['fields' => ['warehouse_code', 'item_sku', 'stock_condition', 'quantity_on_hand', 'last_movement_at'],
            'filters' => ['op' => 'and', 'conditions' => [['field' => 'warehouse_id', 'operator' => 'eq', 'value' => $wh->id]]], 'layout' => ['orientation' => 'landscape']]];

        foreach ([ReportOutputFormat::CSV, ReportOutputFormat::XLSX, ReportOutputFormat::PDF] as $format) {
            $run = $this->runner()->requestExport($definition, $admin, $format);
            $this->assertSame(ReportRunStatus::QUEUED, $run->status);
            $done = $this->runner()->executeExport($run->id);
            $this->assertSame(ReportRunStatus::COMPLETED, $done->status, (string) $done->error_message);
            $this->assertSame(1, $done->row_count);
            $file = $this->runner()->download($run->id, $admin);
            $this->assertFileExists($file['path']);
            $this->assertStringEndsWith('.'.$format->value, $file['filename']);
            if ($format === ReportOutputFormat::CSV) {
                $csv = file_get_contents($file['path']);
                $this->assertStringContainsString($item->sku, $csv);
                $this->assertStringContainsString('Available', $csv, 'enum codes are exported as labels');
            }
            $this->assertDomainError(ErrorCode::PERMISSION_DENIED, fn () => $this->runner()->download($run->id, $this->superAdmin()));
        }
        $this->assertSame(0, DB::table('report_runs')->where('runner_account_id', $admin->id)->whereNotNull('parameters')
            ->whereRaw("CAST(parameters AS CHAR) LIKE '%quantity_on_hand\": \"3%'")->count(), 'runs store metadata, never results');
    }

    public function test_definitions_are_versioned_private_by_default_shareable_and_system_reports_are_read_only(): void
    {
        $service = app(ReportDefinitionService::class);
        $author = $this->actorWith(['reporting.definition.create', 'reporting.definition.update', 'reporting.definition.read', 'reporting.definition.share',
            'reporting.definition.clone', 'reporting.report.run', 'catalog.item.read', 'inventory.lot.read']);
        $other = $this->actorWith(['reporting.definition.read', 'reporting.report.run', 'catalog.item.read']);
        $def = $service->create(['name' => 'My items', 'dataset_code' => 'items', 'definition' => ['fields' => ['sku', 'name']]], $author);
        $this->assertSame(ReportVisibility::PRIVATE, $def->visibility);
        $this->assertDomainError(ErrorCode::PERMISSION_DENIED, fn () => $this->runner()->runWeb($def, $other));
        $this->assertDomainError(ErrorCode::REPORT_FIELD_FORBIDDEN, fn () => $service->create(['name' => 'Costs', 'dataset_code' => 'items',
            'definition' => ['fields' => ['sku', 'standard_cost']]], $author));

        $service->update($def->id, ['definition' => ['fields' => ['sku', 'name', 'status']], 'change_note' => 'Add status'], $author, 1);
        $this->assertSame(2, DB::table('report_definition_versions')->where('report_definition_id', $def->id)->count());
        $service->share($def->id, ReportVisibility::SHARED_COMPANY, [], $author);
        $this->assertArrayHasKey('rows', $this->runner()->runWeb($def->refresh(), $other));

        app(SeedRunner::class)->run(StandardReportsSeed::class);
        $system = ReportDefinition::query()->where('code', 'std_lots_by_expiry')->firstOrFail();
        $this->assertDomainError(ErrorCode::PERMISSION_DENIED, fn () => $service->update($system->id, ['name' => 'Hacked'], $author));
        $copy = $service->clone($system->id, $author);
        $this->assertFalse($copy->is_system_seed);
        $this->assertSame($author->id, $copy->owner_account_id);
    }
}
