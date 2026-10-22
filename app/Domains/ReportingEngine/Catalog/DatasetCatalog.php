<?php

declare(strict_types=1);

namespace App\Domains\ReportingEngine\Catalog;

use App\Domains\ReportingEngine\Datasets\AdministrationDatasets;
use App\Domains\ReportingEngine\Datasets\CatalogDatasets;
use App\Domains\ReportingEngine\Datasets\FulfillmentDatasets;
use App\Domains\ReportingEngine\Datasets\InventoryDatasets;
use App\Domains\ReportingEngine\Datasets\PlanningDatasets;
use App\Domains\ReportingEngine\Datasets\ProcurementDatasets;
use App\Domains\ReportingEngine\Datasets\StocktakingDatasets;
use App\Domains\ReportingEngine\Datasets\TransferDatasets;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Code-defined dataset catalog (Chapter 10 §10.2): installs the `reporting.*` views (deployment
 * role, GRANT SELECT to the reporting role only) and syncs the metadata tables used by the builder.
 */
final class DatasetCatalog
{
    /** @var array<int, class-string<ProvidesDatasets>> */
    public const PROVIDERS = [
        CatalogDatasets::class, InventoryDatasets::class, ProcurementDatasets::class, TransferDatasets::class,
        FulfillmentDatasets::class, StocktakingDatasets::class, PlanningDatasets::class, AdministrationDatasets::class,
    ];

    /** @var array<string, DatasetDefinition>|null */
    private ?array $all = null;

    /** @return array<string, DatasetDefinition> */
    public function all(): array
    {
        if ($this->all === null) {
            $all = [];
            foreach (self::PROVIDERS as $provider) {
                foreach ($provider::datasets() as $d) {
                    if (isset($all[$d->code])) {
                        throw new \LogicException("Duplicate dataset code {$d->code}.");
                    }
                    $all[$d->code] = $d;
                }
            }
            ksort($all);
            $this->all = $all;
        }

        return $this->all;
    }

    public function get(string $code): DatasetDefinition
    {
        return $this->all()[$code] ?? throw DomainException::of(ErrorCode::REPORT_DEFINITION_INVALID, "Unknown dataset `$code`.", ['field' => 'dataset_code']);
    }

    public function has(string $code): bool
    {
        return isset($this->all()[$code]);
    }

    /**
     * (Re)create every view; run by migrations and `platform:upgrade` under the deployment account.
     * Views live in the reporting database, while their unqualified table names resolve against the
     * connection's own (application) database when the view is created. SQL SECURITY DEFINER lets the
     * reporting account, which holds SELECT on the reporting database only, read through them; the
     * database-level grant (database/mysql/setup.sql) covers new views automatically.
     */
    public function installViews(?string $connection = null): int
    {
        $db = DB::connection($connection);
        foreach ($this->all() as $d) {
            $db->statement("CREATE OR REPLACE SQL SECURITY DEFINER VIEW {$d->view()} AS {$d->sql}");
        }

        return count($this->all());
    }

    public function dropViews(?string $connection = null): void
    {
        $db = DB::connection($connection);
        foreach ($this->all() as $d) {
            $db->statement("DROP VIEW IF EXISTS {$d->view()}");
        }
    }

    /** Verify every declared field exists in its view (catches drift between code and SQL). */
    public function verify(?string $connection = null): array
    {
        $problems = [];
        $columns = DB::connection($connection)->table('information_schema.COLUMNS')->where('TABLE_SCHEMA', DatasetDefinition::reportingDatabase())
            ->get(['TABLE_NAME as table_name', 'COLUMN_NAME as column_name'])->groupBy('table_name')->map(fn ($c) => $c->pluck('column_name')->all());
        foreach ($this->all() as $d) {
            $have = $columns[$d->code] ?? null;
            if ($have === null) {
                $problems[] = "{$d->code}: view missing";

                continue;
            }
            foreach (array_merge(array_keys($d->fields), $d->warehouseScope, array_filter([$d->branchScope, $d->ownColumn, $d->primaryKey])) as $col) {
                if (! in_array($col, $have, true)) {
                    $problems[] = "{$d->code}.$col: not in view";
                }
            }
            foreach ($d->relationships as $rel) {
                if (! $this->has($rel->target)) {
                    $problems[] = "{$d->code}.{$rel->code}: unknown target {$rel->target}";
                }
            }
        }

        return $problems;
    }

    /** Upsert report_datasets / fields / relationships; datasets no longer in code are deactivated. */
    public function sync(): array
    {
        return DB::transaction(function () {
            $now = now();
            $seen = [];
            $changed = 0;
            foreach ($this->all() as $d) {
                $seen[] = $d->code;
                $existing = DB::table('report_datasets')->where('code', $d->code)->first(['id', 'definition_hash', 'is_active']);
                if ($existing !== null && $existing->definition_hash === $d->hash() && $existing->is_active) {
                    continue;
                }
                $changed++;
                $id = $existing->id ?? (string) Str::uuid7();
                $row = ['label' => $d->label, 'description' => $d->description, 'source_view' => $d->sourceName(), 'primary_key' => $d->primaryKey,
                    'warehouse_scope_column' => $d->warehouseScope === [] ? null : implode(',', $d->warehouseScope), 'branch_scope_column' => $d->branchScope,
                    'own_records_column' => $d->ownColumn, 'classification' => $d->classification->value, 'required_permission' => $d->permission,
                    'feature_flag' => $d->feature, 'default_sort' => json_encode($d->defaultSort), 'module' => $d->module, 'is_active' => true,
                    'definition_hash' => $d->hash(), 'updated_at' => $now];
                if ($existing === null) {
                    DB::table('report_datasets')->insert($row + ['id' => $id, 'code' => $d->code, 'created_at' => $now]);
                } else {
                    DB::table('report_datasets')->where('id', $id)->update($row);
                }
                DB::table('report_dataset_fields')->where('report_dataset_id', $id)->delete();
                DB::table('report_dataset_relationships')->where('report_dataset_id', $id)->delete();
                $order = 0;
                foreach ($d->fields as $f) {
                    DB::table('report_dataset_fields')->insert(['id' => (string) Str::uuid7(), 'report_dataset_id' => $id, 'code' => $f->code, 'label' => $f->label,
                        'field_type' => $f->type->value, 'enum_code' => $f->enumClass !== null ? $f->enumClass::enumCode() : null,
                        'classification' => $f->classification->value, 'required_permission' => $f->requiredPermission(),
                        'is_aggregatable' => $f->aggregatable, 'is_groupable' => $f->groupable, 'is_filterable' => $f->filterable, 'is_sortable' => $f->sortable,
                        'drill_target' => $f->drill, 'sort_order' => $order += 10, 'created_at' => $now, 'updated_at' => $now]);
                }
                foreach ($d->relationships as $r) {
                    DB::table('report_dataset_relationships')->insert(['id' => (string) Str::uuid7(), 'report_dataset_id' => $id, 'code' => $r->code, 'label' => $r->label,
                        'target_dataset_code' => $r->target, 'join_keys' => json_encode($r->keys), 'cardinality' => $r->cardinality->value,
                        'created_at' => $now, 'updated_at' => $now]);
                }
            }
            $deactivated = DB::table('report_datasets')->whereNotIn('code', $seen)->where('is_active', true)->update(['is_active' => false, 'updated_at' => $now]);

            return ['datasets' => count($seen), 'changed' => $changed, 'deactivated' => $deactivated];
        });
    }
}
