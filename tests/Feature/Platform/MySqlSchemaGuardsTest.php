<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domains\ReportingEngine\Catalog\DatasetCatalog;
use App\Support\Database\Search;
use App\Support\Database\SqlError;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsInventory;
use Tests\TestCase;

/**
 * Guarantees the MySQL schema gives in place of PostgreSQL features (Chapter 13 §13.9): append-only
 * triggers, partial unique indexes expressed as functional indexes, case-sensitive identities with
 * case-insensitive search, and date arithmetic in the reporting views.
 */
final class MySqlSchemaGuardsTest extends TestCase
{
    use BuildsInventory;

    public function test_append_only_rows_cannot_be_changed_even_by_the_schema_owner(): void
    {
        $deploy = DB::connection('mysql_deploy');
        $id = (string) Str::uuid7();
        $deploy->table('audit_events')->insert(['id' => $id, 'event_type' => 'test.append_only', 'correlation_id' => (string) Str::uuid7(), 'occurred_at' => now()]);
        try {
            $changes = [
                'update' => fn () => $deploy->table('audit_events')->where('id', $id)->update(['reason' => 'edited']),
                'delete' => fn () => $deploy->table('audit_events')->where('id', $id)->delete(),
            ];
            foreach ($changes as $kind => $change) {
                try {
                    $change();
                    $this->fail("Expected the append-only trigger to reject the $kind");
                } catch (QueryException $e) {
                    $this->assertTrue(SqlError::isAppendOnlyViolation($e), $e->getMessage());
                }
            }
        } finally {
            // The approved-migration bypass: the deployment account with the session flag set.
            $deploy->statement('SET @stosyncrad_bypass_append_only = 1');
            $deploy->table('audit_events')->where('id', $id)->delete();
            $deploy->statement('SET @stosyncrad_bypass_append_only = NULL');
            $deploy->disconnect();
        }
        $this->assertFalse(DB::table('audit_events')->where('id', $id)->exists());
    }

    public function test_only_one_default_tax_rate_but_any_number_of_others(): void
    {
        DB::table('tax_rates')->insert([$this->taxRate('ZERO'), $this->taxRate('EXEMPT'), $this->taxRate('VAT15', default: true)]);

        try {
            DB::table('tax_rates')->insert($this->taxRate('VAT16', default: true));
            $this->fail('Expected a second default tax rate to be rejected');
        } catch (QueryException $e) {
            $this->assertTrue(SqlError::isDuplicateKey($e));
            $this->assertSame('tax_rates_single_default_uq', SqlError::constraintName($e));
        }
    }

    public function test_codes_are_case_sensitive_while_search_ignores_case_but_not_accents(): void
    {
        DB::table('tax_rates')->insert([$this->taxRate('vat'), $this->taxRate('VAT'), $this->taxRate('Exempté')]);   // distinct identities

        $this->assertSame(1, DB::table('tax_rates')->where('code', 'vat')->count());
        $this->assertSame(2, DB::table('tax_rates')->whereRaw(Search::like('code'), ['%vat%'])->count());
        $this->assertSame(1, DB::table('tax_rates')->whereRaw(Search::like('code'), ['%EXEMPTÉ%'])->count());
        $this->assertSame(0, DB::table('tax_rates')->whereRaw(Search::like('code'), ['%EXEMPTE%'])->count(), 'accents still matter, as with ILIKE');
    }

    public function test_lot_expiry_days_and_horizons_in_the_reporting_view(): void
    {
        $item = $this->item();
        $expected = ['L-PAST' => [-2, 'expired'], 'L-7' => [5, 'within_7_days'], 'L-30' => [20, 'within_30_days'],
            'L-90' => [60, 'within_90_days'], 'L-FAR' => [200, 'beyond_warning']];
        foreach ($expected as $number => [$days]) {
            $this->lot($item, $number, now()->addDays($days)->toDateString());
        }

        $view = app(DatasetCatalog::class)->get('lots')->view();
        $rows = collect(DB::select("SELECT lot_number, days_to_expiry, expiry_bucket FROM $view WHERE item_id = ?", [$item->id]))->keyBy('lot_number');

        foreach ($expected as $number => [$days, $bucket]) {
            $this->assertSame($days, (int) $rows[$number]->days_to_expiry, $number);
            $this->assertSame($bucket, $rows[$number]->expiry_bucket, $number);
        }
    }

    /** @return array<string, mixed> */
    private function taxRate(string $code, bool $default = false): array
    {
        return ['id' => (string) Str::uuid7(), 'code' => $code, 'name' => $code, 'rate' => '15', 'is_default' => $default,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now()];
    }
}
