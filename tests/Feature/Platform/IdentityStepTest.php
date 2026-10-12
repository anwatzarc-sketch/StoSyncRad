<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Platform\Config\PlatformConfig;
use App\Domains\Platform\Services\Preflight;
use App\Domains\Platform\Services\SetupState;
use App\Domains\Platform\Setup\ConfigApplier;
use App\Domains\Platform\Setup\SetupService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\BuildsInventory;
use Tests\TestCase;

/**
 * Setup step 6 (identity & security) through the wizard's HTTP endpoints, sending exactly what the
 * browser sends back — including empty settings, which Laravel converts to null.
 */
final class IdentityStepTest extends TestCase
{
    use BuildsInventory;

    private const PASSWORD = 'Fa5h0K2NOZNqd9Hq!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
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

    /** Walk the wizard to step 6; returns the step's settings as the wizard sends them back. */
    private function reachIdentityStep(): array
    {
        $this->postJson('/setup/session', ['token' => app(SetupService::class)->bootstrap(30)])->assertOk();
        $this->postJson('/setup/steps/welcome', ['acknowledge' => true])->assertOk();
        $this->postJson('/setup/steps/super_admin', ['full_name' => 'Abebe Admin', 'email' => 'admin@adula.example', 'password' => self::PASSWORD])->assertOk();
        $this->postJson('/setup/steps/company_profile', ['legal_name' => 'Adula Pharmaceuticals plc', 'base_currency' => 'ETB'])->assertOk();
        $this->postJson('/setup/steps/brand_kit', ['app_name' => 'Adula Stock', 'app_short_name' => 'Adula'])->assertOk();
        $this->postJson('/setup/steps/localization', ['config' => []])->assertOk();
        $step = $this->getJson('/setup/steps/identity_security')->assertOk()->json('data.current');
        $secrets = array_column(array_filter($step['definitions'], fn ($d) => $d['sensitive']), 'key');

        return array_diff_key($step['config'], array_flip($secrets));
    }

    private function admin(): UserAccount
    {
        return UserAccount::query()->where('email', 'admin@adula.example')->firstOrFail();
    }

    private function errorFor(string $field, $response): ?string
    {
        return collect($response->json('errors'))->firstWhere('property', $field)['message'] ?? null;
    }

    public function test_correct_current_password_confirms_settings_sent_back_unchanged(): void
    {
        $config = $this->reachIdentityStep();
        $this->assertArrayHasKey('identity.sso.issuer', $config, 'empty text settings are part of what the wizard sends');
        $this->postJson('/setup/steps/identity_security', ['config' => $config, 'current_password' => self::PASSWORD])
            ->assertOk()->assertJsonPath('data.result.password_rotated', false);
        $this->assertTrue(Hash::check(self::PASSWORD, $this->admin()->password_hash), 'password unchanged');
    }

    public function test_wrong_or_missing_current_password_is_reported_on_that_field(): void
    {
        $config = $this->reachIdentityStep();
        $wrong = $this->postJson('/setup/steps/identity_security', ['config' => $config, 'current_password' => 'Not-the-password-123'])->assertStatus(422);
        $this->assertSame('The current password is not correct.', $this->errorFor('current_password', $wrong));
        $missing = $this->postJson('/setup/steps/identity_security', ['config' => $config])->assertStatus(422);
        $this->assertStringContainsString('Enter your current password', $this->errorFor('current_password', $missing));
    }

    public function test_stricter_policy_requires_a_confirmed_new_password_and_is_all_or_nothing(): void
    {
        $config = $this->reachIdentityStep();
        $config['identity.password.min_length'] = 20;

        $noNew = $this->postJson('/setup/steps/identity_security', ['config' => $config, 'current_password' => self::PASSWORD])->assertStatus(422);
        $this->assertStringContainsString('at least 20 characters', $this->errorFor('new_password', $noNew));
        app(PlatformConfig::class)->forget();
        $this->assertSame(12, app(PlatformConfig::class)->int('identity.password.min_length'), 'nothing is applied when the password part fails');

        $mismatch = $this->postJson('/setup/steps/identity_security', ['config' => $config, 'current_password' => self::PASSWORD,
            'new_password' => 'Adula-Long-Password-2026!', 'new_password_confirmation' => 'Adula-Long-Password-2O26!'])->assertStatus(422);
        $this->assertNotNull($this->errorFor('new_password_confirmation', $mismatch));

        $tooShort = $this->postJson('/setup/steps/identity_security', ['config' => $config, 'current_password' => self::PASSWORD,
            'new_password' => 'Short-Pass-9!', 'new_password_confirmation' => 'Short-Pass-9!'])->assertStatus(422);
        $this->assertStringContainsString('at least 20 characters', $this->errorFor('new_password', $tooShort));

        $this->postJson('/setup/steps/identity_security', ['config' => $config, 'current_password' => self::PASSWORD,
            'new_password' => 'Adula-Long-Password-2026!', 'new_password_confirmation' => 'Adula-Long-Password-2026!'])
            ->assertOk()->assertJsonPath('data.result.password_rotated', true);
        $this->assertTrue(Hash::check('Adula-Long-Password-2026!', $this->admin()->password_hash));
        app(PlatformConfig::class)->forget();
        $this->assertSame(20, app(PlatformConfig::class)->int('identity.password.min_length'));
    }

    public function test_an_optional_new_password_is_applied_even_when_the_current_one_complies(): void
    {
        $config = $this->reachIdentityStep();
        $this->postJson('/setup/steps/identity_security', ['config' => $config, 'current_password' => self::PASSWORD,
            'new_password' => 'Another-Strong-Pass-77', 'new_password_confirmation' => 'Another-Strong-Pass-77'])
            ->assertOk()->assertJsonPath('data.result.password_rotated', true);
        $this->assertTrue(Hash::check('Another-Strong-Pass-77', $this->admin()->password_hash));
        $this->app['auth']->forgetGuards();   // the browser's next request loads the account afresh from the database
        $this->getJson('/setup/state')->assertOk()->assertJsonPath('data.authorized', true)->assertJsonPath('data.super_admin_signed_in', true);

        $reuse = $this->postJson('/setup/steps/identity_security', ['config' => $config, 'current_password' => 'Another-Strong-Pass-77',
            'new_password' => self::PASSWORD, 'new_password_confirmation' => self::PASSWORD])->assertStatus(422);
        $this->assertStringContainsString('must differ from your last', $this->errorFor('new_password', $reuse));
    }

    public function test_empty_settings_round_trip_and_never_wipe_a_stored_secret(): void
    {
        $this->setConfig('integration.carrier.webhook_secret', 'keep-this-secret');
        $applier = app(ConfigApplier::class);
        $applier->apply(['integration.carrier.webhook_secret' => null, 'messaging.mail.from_name' => null], ['integration.', 'messaging.'], null);
        $applier->apply(['fulfillment.sales.walk_in_customer_partner_id' => null], ['fulfillment.'], null);
        app(PlatformConfig::class)->forget();
        $this->assertSame('keep-this-secret', app(PlatformConfig::class)->get('integration.carrier.webhook_secret'));
        $this->assertSame('', app(PlatformConfig::class)->get('messaging.mail.from_name'));
        $this->assertNull(app(PlatformConfig::class)->get('fulfillment.sales.walk_in_customer_partner_id'));
    }
}
