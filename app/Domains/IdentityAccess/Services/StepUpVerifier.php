<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Services;

use App\Domains\IdentityAccess\Enums\MfaPolicy;
use App\Domains\IdentityAccess\Enums\PermissionSensitivity;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\IdentityAccess\Permissions\PermissionRegistry;
use App\Domains\Platform\Config\PlatformConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Step-up verification state (Chapter 04 §4.2): a recent MFA (or, when MFA is off, password)
 * re-verification stored in the session, valid for security.step_up.validity_minutes.
 */
final class StepUpVerifier
{
    public const SESSION_KEY = 'step_up_verified_at';

    public function __construct(private readonly PlatformConfig $config, private readonly PermissionRegistry $registry) {}

    public function isSatisfied(Request $request, UserAccount $account): bool
    {
        if (! $request->hasSession()) {
            // Token-authenticated API clients cannot step up interactively; service accounts
            // are never granted critical permissions interactively (Chapter 04 §4.1).
            return false;
        }
        $at = $request->session()->get(self::SESSION_KEY);
        if (! is_int($at)) {
            return false;
        }

        return (time() - $at) <= $this->config->int('security.step_up.validity_minutes') * 60;
    }

    public function mark(Request $request): void
    {
        $request->session()->put(self::SESSION_KEY, time());
    }

    public function clear(Request $request): void
    {
        $request->session()->forget(self::SESSION_KEY);
    }

    /** Whether the permission demands step-up: critical sensitivity or a configured pattern. */
    public function requiredFor(string $permission): bool
    {
        $definition = $this->registry->get($permission);
        if ($definition?->sensitivity === PermissionSensitivity::CRITICAL) {
            return true;
        }
        foreach ($this->config->list('security.step_up.permissions') as $pattern) {
            if (is_string($pattern) && Str::is($pattern, $permission)) {
                return true;
            }
        }

        return false;
    }

    /** The factor step-up must use for this deployment: 'mfa' or 'password'. */
    public function factor(): string
    {
        return $this->config->get('identity.mfa.policy') === MfaPolicy::OFF->value ? 'password' : 'mfa';
    }
}
