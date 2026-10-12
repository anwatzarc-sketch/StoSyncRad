<?php

declare(strict_types=1);

namespace Tests\Feature\CustomerPortal;

use App\Domains\CustomerPortal\Models\CustomerInvitation;
use App\Domains\CustomerPortal\Models\CustomerRegistration;
use App\Domains\IdentityAccess\Actions\AssignRoleAction;
use App\Domains\IdentityAccess\Actions\CreateUserAccountAction;
use App\Domains\IdentityAccess\Actions\SaveRoleAction;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Organization\Models\Warehouse;
use App\Domains\Partners\Models\Partner;
use App\Domains\Platform\Enums\PlatformSetupState;
use App\Domains\Platform\Services\SetupState;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\BuildsActors;
use Tests\Support\BuildsInventory;
use Tests\Support\BuildsPortal;
use Tests\TestCase;

/** CP-1.1 sections 2–4: the customer/staff boundary, separate sign-in, registration, invitations and TFA. */
final class PortalAccessTest extends TestCase
{
    use BuildsActors;
    use BuildsInventory;
    use BuildsPortal;

    private Warehouse $wh;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->withHeader('Referer', (string) config('app.url'));   // the portal SPA calls the API from its own origin
        DB::table('platform_setup')->delete();
        DB::table('platform_setup')->insert(['id' => (string) Str::uuid7(), 'deployment_id' => (string) Str::uuid7(), 'state' => PlatformSetupState::COMPLETED->value,
            'completed_steps' => '[]', 'completed_at' => now(), 'locked_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        app(SetupState::class)->refresh();
        $this->wh = $this->warehouse('PW'.Str::upper(Str::random(4)));
        $this->enablePortal($this->wh);
    }

    private function as(?UserAccount $account): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->flushHeaders();
        $this->withHeader('Referer', (string) config('app.url'));

        return $account === null ? $this : $this->actingAs($account, 'web');
    }

    public function test_the_portal_does_not_exist_while_switched_off(): void
    {
        $this->setConfig('feature.customer_portal', false);
        $this->get('/portal')->assertNotFound();
        $this->getJson('/api/v1/portal/public/info')->assertNotFound();
        $this->postJson('/api/v1/portal/public/login', ['identifier' => 'x@example.test', 'password' => 'whatever-123'])->assertNotFound();
    }

    public function test_visitors_see_only_public_information(): void
    {
        $this->onlineItem('25.00');
        $this->get('/portal')->assertOk();
        $this->get('/portal/register')->assertOk();
        $info = $this->getJson('/api/v1/portal/public/info')->assertOk()->json('data');
        $this->assertSame('approval', $info['registration_mode']);
        $this->assertStringNotContainsString('25.00', json_encode($info), 'no prices for visitors');
        $this->getJson('/api/v1/portal/catalog')->assertUnauthorized();
        $this->get('/portal/dashboard')->assertRedirect('/portal/login');
    }

    public function test_customer_and_staff_sessions_stay_on_their_side(): void
    {
        $member = $this->customerMember($this->customerPartner());
        $this->as($member)->getJson('/api/v1/portal/me')->assertOk()->assertJsonPath('data.customer.name', 'Acme Pharmacy');
        $this->as($member)->getJson('/api/v1/items')->assertForbidden();
        $this->as($member)->getJson('/api/v1/sales-orders')->assertForbidden();
        $this->as($member)->get('/')->assertRedirect('/portal/dashboard');
        $this->as($member)->get('/r/sales-orders')->assertRedirect('/portal/dashboard');
        $this->as($member)->get('/portal/dashboard')->assertOk();

        $staff = $this->superAdmin()->refresh();
        $this->as($staff)->getJson('/api/v1/portal/orders')->assertForbidden();
        $this->as($staff)->getJson('/api/v1/portal/me')->assertForbidden();
        $this->as($staff)->get('/portal/dashboard')->assertRedirect('/');
        $this->as($staff)->get('/portal')->assertOk();
    }

    public function test_customer_accounts_cannot_be_given_staff_roles_or_be_created_by_staff_screens(): void
    {
        $member = $this->customerMember($this->customerPartner());
        $role = DB::table('roles')->whereNotIn('code', ['__super_admin', '__customer_member', '__customer_admin'])->value('id')
            ?? app(SaveRoleAction::class)->execute(['code' => 'clerk', 'name' => 'Clerk', 'permissions' => ['catalog.item.read']], null)->id;
        $this->expectDomain('POLICY_VIOLATION', fn () => app(AssignRoleAction::class)->execute(['user_account_id' => $member->id, 'role_id' => $role, 'scope_type' => 'company'], null));
        $person = $member->person;
        $this->expectDomain('POLICY_VIOLATION', fn () => app(CreateUserAccountAction::class)->execute(['person_id' => $person->id, 'username' => 'x-'.Str::random(5),
            'email' => Str::random(6).'@example.test', 'account_type' => 'customer'], null));
        foreach ([false, true] as $admin) {
            $this->assertSame(['customer.'], array_unique(array_map(fn ($c) => substr($c, 0, 9), DB::table('role_permissions')->where('role_id', $this->portalRole($admin))->pluck('permission_code')->all())),
                'the customer roles hold customer permissions only');
        }
        $this->assertFalse(DB::table('role_permissions')->where('role_id', $this->portalRole())->where('permission_code', 'customer.member.manage')->exists(),
            'only customer administrators manage colleagues');
    }

    public function test_customers_and_staff_sign_in_separately_and_customers_always_use_two_step_verification(): void
    {
        $member = $this->customerMember($this->customerPartner());
        $this->postJson('/api/v1/auth/login', ['identifier' => $member->email, 'password' => self::CUSTOMER_PASSWORD])->assertUnauthorized();
        $staff = $this->account();
        $this->postJson('/api/v1/portal/public/login', ['identifier' => $staff->email, 'password' => 'Correct-Horse-9'])->assertUnauthorized();

        $this->postJson('/api/v1/portal/public/login', ['identifier' => strtoupper($member->email), 'password' => self::CUSTOMER_PASSWORD])
            ->assertOk()->assertJsonPath('data.status', 'mfa_required');
        $this->postJson('/api/v1/portal/public/mfa', ['code' => $this->totpCode($member)])->assertOk()->assertJsonPath('data.status', 'authenticated');
        $this->getJson('/api/v1/portal/me')->assertOk()->assertJsonPath('data.account.email', $member->email);
    }

    public function test_a_new_confirmation_link_replaces_the_old_one_without_revealing_who_registered(): void
    {
        $form = ['full_name' => 'Abel Tesfaye', 'email' => 'abel@pharmacy.example', 'company_name' => 'Abel Pharmacy', 'password' => 'Clinic-Strong-Pass-1',
            'password_confirmation' => 'Clinic-Strong-Pass-1', 'accept_terms' => true];
        $this->postJson('/api/v1/portal/public/register', $form)->assertStatus(202);
        $registration = CustomerRegistration::query()->where('email', 'abel@pharmacy.example')->firstOrFail();
        $first = $this->lastVerificationToken();

        $unknown = $this->postJson('/api/v1/portal/public/register/resend', ['email' => 'nobody@pharmacy.example'])->assertStatus(202);
        $this->assertSame($first, $this->lastVerificationToken(), 'nothing is sent for an unknown address');
        $known = $this->postJson('/api/v1/portal/public/register/resend', ['email' => 'ABEL@pharmacy.example'])->assertStatus(202);
        $this->assertSame(str_replace('nobody', 'abel', (string) $unknown->json('data.message')), $known->json('data.message'), 'the same answer either way');
        $second = $this->lastVerificationToken();
        $this->assertNotSame($first, $second);

        $this->postJson('/api/v1/portal/public/verify-email', ['registration' => $registration->id, 'token' => $first])->assertStatus(422);   // replaced
        $this->postJson('/api/v1/portal/public/verify-email', ['registration' => $registration->id, 'token' => $second])->assertOk()
            ->assertJsonPath('data.status', 'pending_mfa');

        // Lost the e-mail before setting up two-step verification: a new link continues the set-up.
        $this->as(null)->postJson('/api/v1/portal/public/register/resend', ['email' => 'abel@pharmacy.example'])->assertStatus(202);
        $third = $this->lastVerificationToken();
        $this->postJson('/api/v1/portal/public/verify-email', ['registration' => $registration->id, 'token' => 'wrong'])->assertStatus(422);
        $this->postJson('/api/v1/portal/public/verify-email', ['registration' => $registration->id, 'token' => $third])->assertOk()
            ->assertJsonPath('data.status', 'pending_mfa');
        $this->postJson('/api/v1/portal/public/security/begin')->assertOk();

        // Three links per address per hour.
        $this->postJson('/api/v1/portal/public/register/resend', ['email' => 'abel@pharmacy.example'])->assertStatus(202);
        $this->postJson('/api/v1/portal/public/register/resend', ['email' => 'abel@pharmacy.example'])->assertStatus(429)->assertJsonPath('code', 'RATE_LIMITED');
        $this->assertSame(3, DB::table('audit_events')->where('event_type', 'portal.registration.verification_resent')->where('subject_id', $registration->id)->count());
    }

    private function lastVerificationToken(): ?string
    {
        $messages = app('mailer')->getSymfonyTransport()->messages();
        $last = $messages->count() > 0 ? $messages->last()->getOriginalMessage()->getHtmlBody() : '';

        return preg_match('/verify-email\?registration=[0-9a-f-]+&(?:amp;)?token=([A-Za-z0-9]+)/', (string) $last, $m) ? $m[1] : null;
    }

    public function test_self_registration_needs_email_verification_two_step_verification_and_staff_approval(): void
    {
        $form = ['full_name' => 'Hana Bekele', 'email' => 'Hana@NewClinic.example', 'phone' => '+251911000000', 'company_name' => 'New Clinic plc',
            'tax_id' => '0012345678', 'password' => 'Clinic-Strong-Pass-1', 'password_confirmation' => 'Clinic-Strong-Pass-1', 'accept_terms' => true];
        $this->postJson('/api/v1/portal/public/register', $form + ['customer_partner_id' => (string) Str::uuid7()])->assertStatus(422);   // cannot choose a customer
        $this->postJson('/api/v1/portal/public/register', ['password_confirmation' => 'different'] + $form)->assertStatus(422);
        $this->postJson('/api/v1/portal/public/register', $form)->assertStatus(202)->assertJsonPath('data.status', 'check_your_email');
        $this->postJson('/api/v1/portal/public/register', $form)->assertStatus(202);   // same answer, no second account
        $this->assertSame(1, UserAccount::query()->whereRaw('lower(email) = ?', ['hana@newclinic.example'])->count());

        $registration = CustomerRegistration::query()->where('email', 'hana@newclinic.example')->firstOrFail();
        $account = UserAccount::query()->findOrFail($registration->user_account_id);
        $this->assertSame('pending_activation', $account->account_status->value);
        $this->assertNull($account->customer_partner_id);
        $this->postJson('/api/v1/portal/public/login', ['identifier' => 'hana@newclinic.example', 'password' => 'Clinic-Strong-Pass-1'])->assertUnauthorized();

        DB::table('customer_registrations')->where('id', $registration->id)->update(['email_token_hash' => hash('sha256', 'known-token')]);
        $this->postJson('/api/v1/portal/public/verify-email', ['registration' => $registration->id, 'token' => 'wrong-token'])->assertStatus(422);
        $this->postJson('/api/v1/portal/public/security/begin')->assertUnauthorized();   // only after the e-mail link
        $this->postJson('/api/v1/portal/public/verify-email', ['registration' => $registration->id, 'token' => 'known-token'])->assertOk()
            ->assertJsonPath('data.status', 'pending_mfa');
        $secret = $this->postJson('/api/v1/portal/public/security/begin')->assertOk()->json('data.secret');
        $this->postJson('/api/v1/portal/public/security/confirm', ['code' => '000000'])->assertStatus(422);
        $done = $this->postJson('/api/v1/portal/public/security/confirm', ['code' => (new Google2FA)->getCurrentOtp($secret)])->assertOk();
        $this->assertSame('pending_approval', $done->json('data.status'));
        $this->assertCount(10, $done->json('data.recovery_codes'));
        $this->postJson('/api/v1/portal/public/login', ['identifier' => 'hana@newclinic.example', 'password' => 'Clinic-Strong-Pass-1'])->assertUnauthorized();

        $approver = $this->actorWith(['portal.registration.approve']);
        $this->as($approver)->postJson("/api/v1/customer-registrations/{$registration->id}/approve", ['create_customer' => true])->assertOk();
        $account->refresh();
        $this->assertSame('active', $account->account_status->value);
        $customer = Partner::query()->findOrFail($account->customer_partner_id);
        $this->assertSame('New Clinic plc', $customer->name);
        $this->assertTrue(DB::table('role_assignments')->where('user_account_id', $account->id)->where('role_id', $this->portalRole(admin: true))->exists(),
            'the first member of a new customer is its portal administrator');

        $this->as(null)->postJson('/api/v1/portal/public/login', ['identifier' => 'hana@newclinic.example', 'password' => 'Clinic-Strong-Pass-1'])
            ->assertJsonPath('data.status', 'mfa_required');
        $this->postJson('/api/v1/portal/public/mfa', ['code' => $this->totpCode($account)])->assertJsonPath('data.status', 'authenticated');
        $this->getJson('/api/v1/portal/me')->assertOk()->assertJsonPath('data.customer.name', 'New Clinic plc');
    }

    public function test_invited_contacts_join_their_customer_once_two_step_verification_is_set_up(): void
    {
        $customer = $this->customerPartner('Harar Health Centre');
        $staff = $this->actorWith(['portal.invitation.create']);
        $this->as($staff)->postJson('/api/v1/customer-invitations', ['partner_id' => $customer->id, 'email' => 'Abdi@HHC.example', 'full_name' => 'Abdi Yusuf'])->assertCreated();
        $invitation = CustomerInvitation::query()->where('email', 'abdi@hhc.example')->firstOrFail();
        DB::table('customer_invitations')->where('id', $invitation->id)->update(['token_hash' => hash('sha256', 'invite-token')]);

        $this->as(null)->getJson("/api/v1/portal/public/invitation?invitation={$invitation->id}&token=nope")->assertStatus(422);
        $this->getJson("/api/v1/portal/public/invitation?invitation={$invitation->id}&token=invite-token")->assertOk()
            ->assertJsonPath('data.customer_name', 'Harar Health Centre');
        $this->postJson('/api/v1/portal/public/invitation/accept', ['invitation' => $invitation->id, 'token' => 'invite-token',
            'password' => 'Invited-Strong-Pass-1', 'password_confirmation' => 'Invited-Strong-Pass-1'])->assertCreated()->assertJsonPath('data.status', 'pending_mfa');
        $secret = $this->postJson('/api/v1/portal/public/security/begin')->json('data.secret');
        $this->postJson('/api/v1/portal/public/security/confirm', ['code' => (new Google2FA)->getCurrentOtp($secret)])->assertJsonPath('data.status', 'approved');

        $account = UserAccount::query()->whereRaw('lower(email) = ?', ['abdi@hhc.example'])->firstOrFail();
        $this->assertSame([$customer->id, 'active'], [$account->customer_partner_id, $account->account_status->value]);
        $this->postJson('/api/v1/portal/public/invitation/accept', ['invitation' => $invitation->id, 'token' => 'invite-token',
            'password' => 'Invited-Strong-Pass-1', 'password_confirmation' => 'Invited-Strong-Pass-1'])->assertStatus(422);   // single use
    }

    private function expectDomain(string $code, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Expected $code");
        } catch (DomainException $e) {
            $this->assertSame($code, $e->errorCode->value);
        }
    }
}
