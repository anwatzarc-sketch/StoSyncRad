<?php

declare(strict_types=1);

namespace App\Domains\ReportingEngine\Services;

use App\Domains\Platform\Seeds\SeedRunner;
use App\Domains\Platform\Seeds\StandardReportsSeed;
use App\Domains\ReportingEngine\Catalog\DatasetCatalog;
use Illuminate\Support\Facades\DB;

/** Reporting checks and seeding used by setup step 13 and `platform:upgrade` (Chapter 02, Chapter 10). */
final class ReportingSetup
{
    public function __construct(private readonly DatasetCatalog $catalog, private readonly SeedRunner $seeds) {}

    /**
     * Prove the reporting connection is read-only and cannot touch base tables.
     *
     * @return array{reachable: bool, read_only: bool, role?: string, default_transaction_read_only?: string, can_read_base_tables?: bool, can_write_any?: bool, error?: string}
     */
    public function probeReadOnly(): array
    {
        try {
            $db = DB::connection(ReportRunner::CONNECTION);
            $role = (string) $db->selectOne('SELECT current_user AS u')->u;
            $ro = (string) $db->selectOne('SHOW default_transaction_read_only')->default_transaction_read_only;
            $baseRead = (bool) $db->selectOne("SELECT has_table_privilege(current_user, 'public.items', 'SELECT') AS p")->p;
            $write = (bool) $db->selectOne("SELECT bool_or(has_table_privilege(current_user, c.oid, 'INSERT,UPDATE,DELETE,TRUNCATE')) AS p
                FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname IN ('public', 'reporting') AND c.relkind IN ('r', 'p')")->p;

            return ['reachable' => true, 'read_only' => $ro === 'on' && ! $baseRead && ! $write, 'role' => $role,
                'default_transaction_read_only' => $ro, 'can_read_base_tables' => $baseRead, 'can_write_any' => $write];
        } catch (\Throwable $e) {
            return ['reachable' => false, 'read_only' => false, 'error' => mb_substr($e->getMessage(), 0, 300)];
        }
    }

    /** @return array{datasets: int, changed: int, deactivated: int} */
    public function syncDatasets(): array
    {
        return $this->catalog->sync();
    }

    /** @return array<string, mixed> */
    public function seedStandardReports(): array
    {
        return $this->seeds->run(StandardReportsSeed::class);
    }

    /** @return array<int, array{code: string, label: string, module: string, fields: int}> */
    public function datasetSummary(): array
    {
        return array_values(array_map(fn ($d) => ['code' => $d->code, 'label' => $d->label, 'module' => $d->module, 'fields' => count($d->fields)],
            $this->catalog->all()));
    }
}
