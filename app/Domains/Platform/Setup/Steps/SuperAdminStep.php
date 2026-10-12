<?php

declare(strict_types=1);

namespace App\Domains\Platform\Setup\Steps;

use App\Domains\IdentityAccess\Actions\CreateUserAccountAction;
use App\Domains\IdentityAccess\Enums\MfaPolicy;
use App\Domains\IdentityAccess\Enums\MfaStatus;
use App\Domains\IdentityAccess\Enums\PersonStatus;
use App\Domains\IdentityAccess\Enums\RoleStatus;
use App\Domains\IdentityAccess\Models\MfaCredential;
use App\Domains\IdentityAccess\Models\Person;
use App\Domains\IdentityAccess\Models\Role;
use App\Domains\IdentityAccess\Models\RoleAssignment;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\IdentityAccess\Permissions\PermissionSyncer;
use App\Domains\Platform\Config\PlatformConfig;
use App\Domains\Platform\Enums\PlatformSetupStep;
use App\Domains\Platform\Setup\SetupContext;
use App\Domains\Platform\Setup\SetupStepHandler;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Step 2 — the bootstrap Super Admin (Chapter 02 §2.5). Creates person + account + the hidden
 * __super_admin assignment; when MFA is required for platform admins the payload must include a
 * TOTP code for the secret issued to this setup session. Recovery codes are returned once. Headless
 * (bundle) setup defers enrolment to the first sign-in, which then requires it.
 *
 * Payload: full_name, email, username?, password, employee_number?, phone?, mfa_code?
 */
final class SuperAdminStep implements SetupStepHandler
{
    public const SESSION_SECRET = 'setup_mfa_secret';

    public function __construct(
        private readonly CreateUserAccountAction $createAccount,
        private readonly PermissionSyncer $permissions,
        private readonly PlatformConfig $config,
    ) {}

    public function step(): PlatformSetupStep
    {
        return PlatformSetupStep::SUPER_ADMIN;
    }

    public function handle(array $payload, SetupContext $context): array
    {
        foreach (['full_name', 'email', 'password'] as $required) {
            if (empty($payload[$required]) || ! is_string($payload[$required])) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, "`$required` is required.", ['field' => $required]);
            }
        }
        if (filter_var($payload['email'], FILTER_VALIDATE_EMAIL) === false) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, '`email` must be a valid email address.', ['field' => 'email']);
        }
        if (UserAccount::query()->where('is_super_admin', true)->exists()) {
            throw DomainException::of(ErrorCode::POLICY_VIOLATION, 'A Super Admin already exists for this deployment.');
        }

        // Headless setup cannot verify an authenticator: enrolment is then forced at first sign-in.
        $mfaRequired = $this->config->bool('identity.mfa.required_for_platform_admins')
            && $this->config->get('identity.mfa.policy') !== MfaPolicy::OFF->value && ! $context->headless;
        $secret = $context->session[self::SESSION_SECRET] ?? null;
        if ($mfaRequired) {
            if (! is_string($secret) || empty($payload['mfa_code'])) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Scan the QR code and enter the 6-digit code from your authenticator app.', ['field' => 'mfa_code']);
            }
            if ((new Google2FA)->verifyKey($secret, (string) $payload['mfa_code'], 1) === false) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'The authenticator code is not valid.', ['field' => 'mfa_code']);
            }
        }

        $this->permissions->sync();

        return DB::transaction(function () use ($payload, $context, $mfaRequired, $secret) {
            $person = new Person(['full_name' => trim((string) $payload['full_name']), 'email' => strtolower((string) $payload['email']),
                'employee_number' => $payload['employee_number'] ?? null, 'phone' => $payload['phone'] ?? null]);
            $person->forceFill(['status' => PersonStatus::ACTIVE])->save();

            $account = $this->createAccount->execute([
                'person_id' => $person->id,
                'username' => (string) ($payload['username'] ?? $payload['email']),
                'email' => (string) $payload['email'],
                'password' => (string) $payload['password'],
            ], null, activate: true, superAdmin: true);

            $role = Role::query()->where('code', Role::SUPER_ADMIN_CODE)->firstOrFail();
            if ($role->status !== RoleStatus::ACTIVE) {
                $role->forceFill(['status' => RoleStatus::ACTIVE])->save();
            }
            RoleAssignment::query()->create(['user_account_id' => $account->id, 'role_id' => $role->id, 'scope_type' => 'company', 'scope_id' => null,
                'reason' => 'Platform Setup bootstrap']);

            $recovery = [];
            if ($mfaRequired) {
                $recovery = array_map(static fn () => strtoupper(Str::random(5).'-'.Str::random(5)), range(1, 10));
                MfaCredential::query()->create([
                    'user_account_id' => $account->id, 'secret' => $secret, 'confirmed_at' => now(),
                    'recovery_code_hashes' => array_map(static fn (string $c) => Hash::make($c), $recovery),
                ]);
                $account->forceFill(['mfa_status' => MfaStatus::ENROLLED])->save();
            }
            $context->session['super_admin_id'] = $account->id;

            return ['account_id' => $account->id, 'username' => $account->username, 'recovery_codes' => $recovery];
        });
    }

    /** Issue the TOTP secret for this setup session. @return array{secret: string, otpauth_uri: string} */
    public static function issueSecret(SetupContext $context, string $email, string $issuer): array
    {
        $engine = new Google2FA;
        $secret = $context->session[self::SESSION_SECRET] ?? $engine->generateSecretKey(32);
        $context->session[self::SESSION_SECRET] = $secret;

        return ['secret' => $secret, 'otpauth_uri' => $engine->getQRCodeUrl($issuer, $email, $secret)];
    }

    public function current(): array
    {
        $admin = UserAccount::query()->where('is_super_admin', true)->first();

        return ['exists' => $admin !== null, 'username' => $admin?->username,
            'mfa_required' => $this->config->bool('identity.mfa.required_for_platform_admins') && $this->config->get('identity.mfa.policy') !== MfaPolicy::OFF->value];
    }
}
