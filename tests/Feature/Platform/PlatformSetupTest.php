<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Organization\Models\Warehouse;
use App\Domains\Platform\Config\PlatformConfig;
use App\Domains\Platform\Enums\PlatformSetupState;
use App\Domains\Platform\Models\PlatformSetup;
use App\Domains\Platform\Services\ConfigBundleService;
use App\Domains\Platform\Services\Preflight;
use App\Domains\Platform\Services\SetupState;
use App\Domains\Platform\Setup\HeadlessSetup;
use App\Domains\Platform\Setup\SetupService;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\BuildsActors;
use Tests\Support\BuildsInventory;
use Tests\TestCase;

/** Chapter 02 §2.8 — setup gate, bootstrap token, step order, headless bundle setup. */
final class PlatformSetupTest extends TestCase
{
    use BuildsActors;
    use BuildsInventory;

    private const PASSWORD = 'Correct-Horse-Battery-9!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // Host-dependent checks (PHP extensions, HTTPS…) are covered by their own unit; here they pass.
        $this->app->instance(Preflight::class, new class extends Preflight
        {
            public function run(): array
            {
                return [['check' => 'environment', 'status' => 'pass', 'message' => 'ok', 'remediation' => null]];
            }
        });
        DB::table('platform_setup')->delete();
        app(SetupState::class)->refresh();
        $this->setConfig('identity.mfa.required_for_platform_admins', false);
    }

    public function test_nothing_but_setup_health_and_brand_answers_before_lock(): void
    {
        $this->get('/')->assertRedirect('/setup');
        $this->get('/stock')->assertRedirect('/setup');
        $this->getJson('/api/v1/items')->assertStatus(503)->assertJsonPath('code', 'SETUP_REQUIRED');
        $this->postJson('/api/v1/auth/login', ['identifier' => 'x', 'password' => 'y'])->assertStatus(503);
        $this->get('/health/live')->assertOk();
        $this->get('/manifest.webmanifest')->assertOk()->assertHeader('ETag');
        $this->get('/brand/theme.css')->assertOk();
        $this->get('/setup')->assertOk();
    }

    public function test_bootstrap_token_is_hashed_single_use_and_expiring(): void
    {
        $token = app(SetupService::class)->bootstrap(30);
        $row = PlatformSetup::query()->firstOrFail();
        $this->assertNotSame($token, $row->bootstrap_token_hash);
        $this->assertSame(hash('sha256', $token), $row->bootstrap_token_hash, 'only the hash is stored');

        $this->postJson('/setup/session', ['token' => $token])->assertOk()->assertJsonPath('data.authorized', true);
        $this->flushSession();
        $this->postJson('/setup/session', ['token' => $token])->assertStatus(401)->assertJsonPath('code', 'SETUP_TOKEN_INVALID');

        $late = app(SetupService::class)->bootstrap(30);
        $this->travel(31)->minutes();
        $this->postJson('/setup/session', ['token' => $late])->assertStatus(401);
        $this->getJson('/setup/state')->assertStatus(401);
    }

    public function test_wizard_steps_run_in_order_and_sign_the_super_admin_in(): void
    {
        $token = app(SetupService::class)->bootstrap(30);
        $this->postJson('/setup/session', ['token' => $token])->assertOk();

        $this->postJson('/setup/steps/company_profile', ['legal_name' => 'Too Early plc', 'base_currency' => 'ETB'])
            ->assertStatus(422)->assertJsonPath('code', 'SETUP_STEP_ORDER');
        $this->postJson('/setup/steps/welcome', ['acknowledge' => true, 'deployment_label' => 'Pilot'])->assertOk();
        $this->postJson('/setup/steps/super_admin', ['full_name' => 'Ada Admin', 'email' => 'ada@example.test', 'password' => self::PASSWORD])
            ->assertOk()->assertJsonPath('data.super_admin_signed_in', true);
        $this->postJson('/setup/steps/company_profile', ['legal_name' => 'Ada Trading plc', 'base_currency' => 'ETB'])->assertOk();

        $this->assertSame('Ada Trading plc', DB::table('company_profile')->value('legal_name'));
        $this->assertTrue(UserAccount::query()->where('email', 'ada@example.test')->value('is_super_admin'));
        $this->getJson('/setup/state')->assertOk()->assertJsonPath('data.steps.2.completed', true);
    }

    public function test_setup_resumed_in_a_new_session_signs_the_super_admin_in_again(): void
    {
        $this->setConfig('identity.mfa.required_for_platform_admins', true);
        $this->setConfig('identity.mfa.policy', 'optional');
        $this->postJson('/setup/session', ['token' => app(SetupService::class)->bootstrap(30)])->assertOk();
        $this->postJson('/setup/steps/welcome', ['acknowledge' => true])->assertOk();
        $secret = $this->postJson('/setup/mfa/secret', ['email' => 'ada@example.test'])->assertOk()->json('data.secret');
        $this->postJson('/setup/steps/super_admin', ['full_name' => 'Ada Admin', 'email' => 'ada@example.test', 'username' => 'platform.admin@example.test',
            'password' => self::PASSWORD, 'mfa_code' => (new Google2FA)->getCurrentOtp($secret)])->assertOk();

        // A new browser session (expired cookie, other device…) with a fresh bootstrap token.
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->postJson('/setup/session', ['token' => app(SetupService::class)->bootstrap(30)])->assertOk()
            ->assertJsonPath('data.authorized', true)->assertJsonPath('data.super_admin_signed_in', false);
        $this->postJson('/setup/steps/company_profile', ['legal_name' => 'Ada Trading plc', 'base_currency' => 'ETB'])
            ->assertStatus(401)->assertJsonPath('message', 'Sign in as the Super Admin to continue setup.');

        $wrong = $this->postJson('/setup/sign-in', ['identifier' => 'platform.admin@example.test', 'password' => 'Wrong-Password-123'])->assertStatus(422);
        $this->assertSame('password', $wrong->json('errors.0.property'));
        $noCode = $this->postJson('/setup/sign-in', ['identifier' => 'platform.admin@example.test', 'password' => self::PASSWORD])->assertStatus(422);
        $this->assertSame('code', $noCode->json('errors.0.property'));
        $this->postJson('/setup/sign-in', ['identifier' => 'ADA@example.test', 'password' => self::PASSWORD, 'code' => (new Google2FA)->getCurrentOtp($secret)])
            ->assertOk()->assertJsonPath('data.super_admin_signed_in', true);

        $this->app['auth']->forgetGuards();
        $this->postJson('/setup/steps/company_profile', ['legal_name' => 'Ada Trading plc', 'base_currency' => 'ETB'])->assertOk();
    }

    public function test_headless_bundle_setup_locks_and_its_export_round_trips(): void
    {
        $bundle = [
            'format' => ConfigBundleService::FORMAT, 'version' => ConfigBundleService::VERSION,
            'platform' => ['deployment_label' => 'Client A'],
            'company_profile' => ['legal_name' => 'Client A Trading plc', 'trading_name' => 'Client A', 'base_currency' => 'ETB', 'fiscal_year_start_month' => 7],
            'brand_kit' => ['app_name' => 'Client A Stock', 'app_short_name' => 'A-Stock'],
            'configuration' => ['stock.backdating_window_days' => 3, 'locale.timezone' => 'Africa/Addis_Ababa', 'planning.default_window_days' => 60],
            'organization' => ['branches' => [['code' => 'HQ', 'name' => 'Head office', 'kind' => 'headquarters',
                'warehouses' => [['code' => 'HQ-MAIN', 'name' => 'Main store']]]]],
            'catalog_foundations' => ['taxonomy' => ['PACK']],
            'role_templates' => 'none',
            'roles' => [['code' => 'store_clerk', 'name' => 'Store clerk', 'permissions' => ['inventory.stock.read', 'catalog.item.read']]],
        ];
        $result = app(HeadlessSetup::class)->run($bundle, ['full_name' => 'Bea Admin', 'email' => 'bea@example.test', 'password' => self::PASSWORD]);

        app(SetupState::class)->refresh();
        $this->assertSame(PlatformSetupState::COMPLETED, app(SetupState::class)->state());
        $this->assertSame('bea@example.test', $result['super_admin']);
        $this->assertSame('Client A Trading plc', DB::table('company_profile')->value('legal_name'));
        $this->assertTrue(Warehouse::query()->where('code', 'HQ-MAIN')->exists());
        app(PlatformConfig::class)->forget();
        $this->assertSame(3, app(PlatformConfig::class)->int('stock.backdating_window_days'));
        $this->assertSame(60, app(PlatformConfig::class)->int('planning.default_window_days'), 'keys no step owns are applied too');
        $this->assertSame(['catalog.item.read', 'inventory.stock.read'],
            DB::table('role_permissions as rp')->join('roles as r', 'r.id', '=', 'rp.role_id')->where('r.code', 'store_clerk')->orderBy('permission_code')->pluck('permission_code')->all());
        $this->assertNotNull(PlatformSetup::query()->value('config_bundle_checksum'));

        // The exported bundle re-applies as a no-op (same configuration).
        $export = app(ConfigBundleService::class)->export(null);
        $this->assertSame(3, $export['configuration']['stock.backdating_window_days']);
        $this->assertSame(['HQ'], array_column($export['organization']['branches'], 'code'));
        $report = app(ConfigBundleService::class)->import($export, null, dryRun: true);
        $this->assertSame(0, $report['sections']['configuration']['updated']);

        $this->get('/setup')->assertNotFound();
        $this->expectExceptionMessage('already completed');
        app(HeadlessSetup::class)->run($bundle, ['full_name' => 'X', 'email' => 'x@example.test', 'password' => self::PASSWORD]);
    }
}
