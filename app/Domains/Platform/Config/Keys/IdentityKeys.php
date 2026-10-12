<?php

declare(strict_types=1);

namespace App\Domains\Platform\Config\Keys;

use App\Domains\IdentityAccess\Enums\AccountsPerPersonPolicy;
use App\Domains\IdentityAccess\Enums\MfaPolicy;
use App\Domains\IdentityAccess\Enums\PersonIdentityKey;
use App\Domains\IdentityAccess\Enums\UsernamePolicy;
use App\Domains\Platform\Config\DefinesConfigKeys;
use App\Domains\Platform\Config\ProvidesConfigKeys;

final class IdentityKeys implements ProvidesConfigKeys
{
    use DefinesConfigKeys;

    public static function definitions(): array
    {
        return [
            self::enum('identity.accounts_per_person', AccountsPerPersonPolicy::class, 'single', 'Accounts per person', 'Whether a person may own one human login account or several (corporate policy).'),
            self::int('identity.max_accounts_per_person', 3, 'Maximum accounts per person', 'Upper bound of human accounts per person when the policy is "multiple".', 'G', 1, 20),
            self::enum('identity.person_identity_key', PersonIdentityKey::class, 'email', 'Person identity key', 'Attribute used to match an existing person when creating accounts.'),
            self::bool('identity.service_accounts_enabled', true, 'Service accounts', 'Allow non-interactive integration accounts with scoped API tokens.'),
            self::enum('identity.username_policy', UsernamePolicy::class, 'either', 'Username policy', 'Whether usernames are the email, a custom handle, or either.'),
            self::int('identity.password.min_length', 12, 'Minimum password length', 'Minimum number of characters for passwords.', 'G', 8, 128),
            self::int('identity.password.require_classes', 3, 'Required character classes', 'How many of: lowercase, uppercase, digits, symbols must appear.', 'G', 1, 4),
            self::int('identity.password.history', 5, 'Password history', 'Number of previous passwords that cannot be reused.', 'G', 0, 24),
            self::int('identity.password.max_age_days', 90, 'Password maximum age', 'Days before a password must be changed (0 = never).', 'G', 0, 365),
            self::bool('identity.password.breach_check', false, 'Breached password check', 'Reject passwords found in known breach corpora (requires outbound HTTPS).'),
            self::int('identity.lockout.threshold', 5, 'Lockout threshold', 'Consecutive failed logins before the account is temporarily locked.', 'G', 3, 20),
            self::int('identity.lockout.duration_minutes', 15, 'Lockout duration', 'Minutes an account stays locked after reaching the threshold.', 'G', 1, 1440),
            self::enum('identity.mfa.policy', MfaPolicy::class, 'required_for_sensitive', 'MFA policy', 'Who must use multi-factor authentication.'),
            self::bool('identity.mfa.required_for_platform_admins', true, 'MFA for platform admins', 'Always require MFA for the Super Admin and holders of platform.platform.manage.'),
            self::int('identity.mfa.trusted_device_days', 30, 'Trusted device duration', 'Days a device is remembered after a successful MFA challenge (0 = never).', 'G', 0, 90),
            self::bool('identity.self_service_password_reset', true, 'Self-service password reset', 'Allow users to reset their own password by email.'),
            self::bool('identity.sso.enabled', false, 'Single sign-on', 'Enable OIDC single sign-on (local accounts remain available).'),
            self::str('identity.sso.issuer', '', 'SSO issuer', 'OIDC issuer URL.', 'G', ['max_length' => 2048]),
            self::str('identity.sso.client_id', '', 'SSO client id', 'OIDC client identifier.', 'G', ['max_length' => 255]),
            self::secret('identity.sso.client_secret', 'SSO client secret', 'OIDC client secret (encrypted at rest).'),
            self::bool('identity.sso.jit_provisioning', false, 'SSO just-in-time provisioning', 'Create accounts automatically on first SSO login (no roles are granted).'),
        ];
    }
}
