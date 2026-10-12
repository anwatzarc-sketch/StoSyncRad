<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domains\IdentityAccess\Permissions\PermissionSyncer;
use App\Domains\Platform\Seeds\CategoryTaxonomySeed;
use App\Domains\Platform\Seeds\RoleTemplatesSeed;
use App\Domains\Platform\Seeds\SeedRunner;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Chapter 02 §2.6 / Chapter 15 §15.3 — seeds are complete and idempotent. */
final class SeedsTest extends TestCase
{
    private const TABLES = ['uom_categories', 'units_of_measure', 'storage_categories', 'lookups', 'lookup_values', 'document_sequences',
        'sod_rules', 'approval_policies', 'inventory_locations', 'qc_checklist_templates', 'templates', 'item_categories', 'roles',
        'role_permissions', 'permissions', 'permission_implications'];

    public function test_all_seeds_run_and_a_second_run_is_a_no_op(): void
    {
        $runner = app(SeedRunner::class);
        $this->runEverything($runner);
        $first = $this->counts();

        $this->runEverything($runner);
        $this->assertSame($first, $this->counts(), 'Re-running seeds must not change any row counts');

        $this->assertGreaterThan(40, $first['units_of_measure']);
        $this->assertSame(24, DB::table('item_categories')->whereNull('parent_id')->count(), 'Appendix E: 24 top-level groups');
        $this->assertSame(24, DB::table('item_categories')->where('code', 'like', '%.OTHER')->count(), 'every group ends with an Other leaf');
        $this->assertSame(DB::table('item_categories')->count(), DB::table('item_categories')->distinct()->count('full_path'));
        $this->assertSame(10, DB::table('roles')->where('is_template_origin', true)->count());
        $this->assertSame(1, DB::table('roles')->where('code', '__super_admin')->where('is_system', true)->count());
    }

    public function test_category_policy_defaults_follow_appendix_e(): void
    {
        app(SeedRunner::class)->runAll(SeedRunner::core());
        app(SeedRunner::class)->run(CategoryTaxonomySeed::class);

        $med = DB::table('item_categories')->where('code', 'MED')->first();
        $this->assertSame('tracked_required', $med->default_expiry_policy);
        $this->assertSame('fefo', $med->default_removal_strategy);
        $vacc = DB::table('item_categories')->where('code', 'MED.VACC')->first();
        $this->assertSame('quarantine_on_receipt', $vacc->default_qc_policy);
        $this->assertSame(DB::table('storage_categories')->where('code', 'cold_chain')->value('id'), $vacc->default_storage_category_id);
        $this->assertSame('serial', DB::table('item_categories')->where('code', 'ITE.COMPUTERS')->value('default_tracking_mode'));
        $this->assertTrue((bool) DB::table('item_categories')->where('code', 'FUEL')->value('is_hazardous'));
    }

    public function test_storekeeper_template_cannot_approve_or_post_adjustments(): void
    {
        app(PermissionSyncer::class)->sync();
        app(SeedRunner::class)->run(RoleTemplatesSeed::class, ['templates' => ['storekeeper']]);

        $codes = DB::table('role_permissions as rp')->join('roles as r', 'r.id', '=', 'rp.role_id')
            ->where('r.code', 'storekeeper')->pluck('rp.permission_code')->all();
        $this->assertContains('transfers.transfer.dispatch', $codes);
        $this->assertNotContains('stocktaking.adjustment.approve', $codes);
        $this->assertNotContains('stocktaking.adjustment.post', $codes);
        $this->assertNotContains('catalog.item.manage_cost', $codes);
    }

    private function runEverything(SeedRunner $runner): void
    {
        app(PermissionSyncer::class)->sync();
        $runner->runAll(SeedRunner::core());
        $runner->run(CategoryTaxonomySeed::class);
        $runner->run(RoleTemplatesSeed::class);
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $out = [];
        foreach (self::TABLES as $table) {
            $out[$table] = DB::table($table)->count();
        }

        return $out;
    }
}
