<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Services;

use App\Domains\IdentityAccess\Enums\AccountType;
use App\Domains\IdentityAccess\Enums\MfaPolicy;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Platform\Config\PlatformConfig;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;

/**
 * What a signed-in person must do before any permission-bound endpoint answers (Chapter 04 §4.1–4.2):
 * change an expired/forced password, enrol MFA where policy requires it. The `auth/*` and `me/*`
 * routes stay reachable so the obligation can be fulfilled. Service accounts are exempt from MFA.
 */
final class AccountObligations
{
    public function __construct(
        private readonly PlatformConfig $config,
        private readonly PasswordPolicy $passwords,
        private readonly TotpService $totp,
    ) {}

    public function pending(UserAccount $account): ?ErrorCode
    {
        if ($account->account_type !== AccountType::SERVICE && ($account->must_change_password || $this->passwords->isExpired($account))) {
            return ErrorCode::PASSWORD_CHANGE_REQUIRED;
        }
        if ($account->account_type !== AccountType::SERVICE && $this->mfaRequired($account) && ! $this->totp->isEnrolled($account)) {
            return ErrorCode::MFA_ENROLLMENT_REQUIRED;
        }

        return null;
    }

    public function assertNone(UserAccount $account): void
    {
        match ($this->pending($account)) {
            ErrorCode::PASSWORD_CHANGE_REQUIRED => throw DomainException::of(ErrorCode::PASSWORD_CHANGE_REQUIRED, 'Change your password to continue.', [],
                'POST /api/v1/me/password'),
            ErrorCode::MFA_ENROLLMENT_REQUIRED => throw DomainException::of(ErrorCode::MFA_ENROLLMENT_REQUIRED, 'Set up multi-factor authentication to continue.', [],
                'POST /api/v1/me/mfa/enroll, then /api/v1/me/mfa/confirm'),
            default => null,
        };
    }

    private function mfaRequired(UserAccount $account): bool
    {
        $policy = MfaPolicy::tryFrom((string) $this->config->get('identity.mfa.policy'));

        return $policy === MfaPolicy::REQUIRED_FOR_ALL
            || ($policy !== MfaPolicy::OFF && $account->is_super_admin && $this->config->bool('identity.mfa.required_for_platform_admins'));
    }
}
