<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domains\IdentityAccess\Enums\AccountStatus;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\IdentityAccess\Services\StepUpVerifier;
use App\Domains\Platform\Enums\PlatformSetupState;
use App\Domains\Platform\Services\ConfigBundleService;
use App\Domains\Platform\Services\SetupState;
use App\Domains\Platform\Seeds\DocumentSequencesSeed;
use App\Domains\Platform\Seeds\SeedRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\BuildsActors;
use Tests\Support\BuildsInventory;
use Tests\TestCase;

/**
 * HTTP surface (Chapters 04, 05, 16): setup gate, one permission per endpoint, middleware order,
 * global payload validation, no-body actions, step-up for critical permissions.
 */
final class ApiSurfaceTest extends TestCase
{
    use BuildsActors;
    use BuildsInventory;

    protected function setUp(): void
    {
        parent::setUp();
        app(SeedRunner::class)->run(DocumentSequencesSeed::class);
        $this->setSetupState(PlatformSetupState::COMPLETED);
    }

    private function setSetupState(?PlatformSetupState $state): void
    {
        DB::table('platform_setup')->delete();
        if ($state !== null) {
            DB::table('platform_setup')->insert(['id' => (string) Str::uuid7(), 'deployment_id' => (string) Str::uuid7(), 'state' => $state->value,
                'completed_steps' => '[]', 'completed_at' => now(), 'locked_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        app(SetupState::class)->refresh();
    }

    /** Integration client: real personal access token (no session, so never stepped-up). */
    private function token(UserAccount $account): static
    {
        $this->app['auth']->forgetGuards();   // guards cache the resolved user across requests in one test

        return $this->withToken($account->createToken('test')->plainTextToken);
    }

    /** Browser session (SPA cookie flow) with an optional recent step-up. */
    private function browser(UserAccount $account, bool $steppedUp = false): static
    {
        return $this->actingAs($account, 'web')->withHeader('Referer', config('app.url'))
            ->withSession($steppedUp ? [StepUpVerifier::SESSION_KEY => time()] : []);
    }

    public function test_only_allow_listed_routes_answer_before_setup_is_locked(): void
    {
        $this->setSetupState(null);
        $this->getJson('/api/v1/meta/brand')->assertOk();

        $this->token($this->actorWith(['catalog.item.read']))->getJson('/api/v1/items')->assertStatus(503)->assertJsonPath('code', 'SETUP_REQUIRED');

        $this->setSetupState(PlatformSetupState::COMPLETED);
        $this->getJson('/api/v1/items')->assertOk();
    }

    public function test_pending_password_change_or_mfa_enrolment_blocks_every_bound_endpoint(): void
    {
        $clerk = $this->actorWith(['catalog.item.read']);
        $clerk->forceFill(['must_change_password' => true])->save();
        $this->token($clerk)->getJson('/api/v1/items')->assertStatus(403)->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
        $this->getJson('/api/v1/me')->assertOk();

        $this->setConfig('identity.mfa.required_for_platform_admins', true);
        $this->setConfig('identity.mfa.policy', 'optional');
        $this->token($this->superAdmin())->getJson('/api/v1/items')->assertStatus(403)->assertJsonPath('code', 'MFA_ENROLLMENT_REQUIRED');
    }

    public function test_unauthenticated_callers_are_rejected_before_payload_validation(): void
    {
        $this->postJson('/api/v1/items', ['tracking_mode' => 'bogus'])->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_each_endpoint_requires_its_bound_permission(): void
    {
        $item = $this->item();
        $this->token($this->actorWith(['catalog.item.read', 'catalog.item.deactivate']))->postJson("/api/v1/items/{$item->id}/archive")->assertStatus(403)->assertJsonPath('code', 'PERMISSION_DENIED');
        $this->postJson("/api/v1/items/{$item->id}/deactivate")->assertOk()->assertJsonPath('data.status', 'inactive');

        $this->token($this->actorWith(['catalog.item.archive']))->postJson("/api/v1/items/{$item->id}/archive")->assertOk()->assertJsonPath('data.status', 'archived');
    }

    public function test_enum_properties_fail_with_a_clear_error_naming_the_allowed_values(): void
    {
        $item = $this->item();
        $response = $this->browser($this->actorWith(['catalog.item.convert_tracking']), steppedUp: true)
            ->postJson("/api/v1/items/{$item->id}/convert-tracking", ['tracking_mode' => 'bogus', 'reason' => 'test'])
            ->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');
        $error = collect($response->json('errors'))->firstWhere('property', 'tracking_mode');
        $this->assertNotNull($error, 'the failing property is named');
        $this->assertStringContainsString('lot', strtolower(json_encode($error)), 'the message lists the allowed values');
    }

    public function test_variant_endpoints_bind_one_permission_each(): void
    {
        $target = $this->account();
        $locker = $this->actorWith(['identity.account.lock']);
        $this->browser($locker)->postJson("/api/v1/admin/accounts/{$target->id}/suspend", ['reason' => 'x'])->assertStatus(403);
        $this->browser($locker)->postJson("/api/v1/admin/accounts/{$target->id}/lock", ['reason' => 'Shared password'])->assertOk()->assertJsonPath('data.account_status', 'locked');
        $this->assertSame(AccountStatus::LOCKED, $target->refresh()->account_status);
    }

    public function test_no_body_actions_reject_payloads(): void
    {
        $this->token($this->actorWith(['exchange.import.read']))->postJson('/api/v1/imports/'.Str::uuid7().'/confirm', ['force' => true])->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');
    }

    public function test_writable_by_rules_see_a_token_authenticated_actor(): void
    {
        $item = $this->item();
        $actor = $this->actorWith(['catalog.item.read', 'catalog.barcode.manage']);
        $token = $actor->createToken('test')->plainTextToken;
        $this->withToken($token)->putJson("/api/v1/items/{$item->id}/barcodes", ['barcodes' => [['barcode' => 'BC-'.Str::upper(Str::random(8)), 'is_primary' => true]]])
            ->assertOk();
        $this->assertSame(1, DB::table('item_barcodes')->where('item_id', $item->id)->count());
    }

    public function test_critical_permissions_demand_a_recent_step_up(): void
    {
        $admin = $this->actorWith(['platform.bundle.export']);
        $this->token($admin)->getJson('/api/v1/admin/config-bundle')->assertStatus(403)->assertJsonPath('code', 'STEP_UP_REQUIRED');

        $this->browser($admin, steppedUp: true)->getJson('/api/v1/admin/config-bundle')
            ->assertOk()->assertJsonPath('format', ConfigBundleService::FORMAT);
    }

    public function test_configuration_bundles_round_trip(): void
    {
        $this->setConfig('stock.backdating_window_days', 9);
        $admin = $this->actorWith(['platform.bundle.export', 'platform.bundle.import']);
        $bundle = $this->browser($admin, steppedUp: true)->getJson('/api/v1/admin/config-bundle')->assertOk()->json();
        $this->assertSame(9, $bundle['configuration']['stock.backdating_window_days']);

        $bundle['configuration']['stock.backdating_window_days'] = 4;
        $dry = $this->browser($admin, steppedUp: true)->postJson('/api/v1/admin/config-bundle?dry_run=1', $bundle)->assertOk()->json('data');
        $this->assertTrue($dry['dry_run']);
        $this->assertSame(1, $dry['sections']['configuration']['updated']);
        $this->assertContains('checksum', array_column($dry['warnings'], 'section'), 'edits after export are reported');
        $this->assertSame(9, app(\App\Domains\Platform\Config\PlatformConfig::class)->int('stock.backdating_window_days'), 'dry run writes nothing');

        $report = $this->browser($admin, steppedUp: true)->postJson('/api/v1/admin/config-bundle', $bundle)->assertOk()->json('data');
        $this->assertSame(1, $report['sections']['configuration']['updated']);
        $this->assertSame(4, app(\App\Domains\Platform\Config\PlatformConfig::class)->int('stock.backdating_window_days'));

        $this->browser($admin, steppedUp: true)->postJson('/api/v1/admin/config-bundle', ['format' => 'other'] + $bundle)
            ->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');
    }

    public function test_report_exports_are_bound_per_format(): void
    {
        Queue::fake();
        $this->token($this->actorWith(['reporting.report.run', 'reporting.report.export_csv', 'inventory.stock.read']));
        $draft = ['dataset_code' => 'stock_positions', 'definition' => ['fields' => ['warehouse_code', 'item_sku', 'quantity_on_hand']]];
        $this->postJson('/api/v1/report-definitions/draft/exports/pdf', $draft)->assertStatus(403);
        $this->postJson('/api/v1/report-definitions/draft/exports/csv', $draft)->assertStatus(202)->assertJsonPath('data.output_format', 'csv');
    }

    public function test_detailed_health_reports_checks_queue_and_outbox(): void
    {
        $this->token($this->actorWith(['platform.health.read']))->getJson('/api/v1/admin/health')->assertOk()->assertJsonStructure(['data' => ['status', 'checks', 'ready' => ['database', 'cache']]]);
    }
}
