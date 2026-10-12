<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\IdentityAccess\Enums\AccountStatus;
use App\Domains\IdentityAccess\Enums\AccountType;
use App\Domains\IdentityAccess\Enums\MfaPolicy;
use App\Domains\IdentityAccess\Enums\MfaStatus;
use App\Domains\IdentityAccess\Enums\SecurityEventType;
use App\Domains\IdentityAccess\Models\SecurityEvent;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\IdentityAccess\Services\Access;
use App\Domains\IdentityAccess\Services\PasswordPolicy;
use App\Domains\IdentityAccess\Services\SessionManager;
use App\Domains\IdentityAccess\Services\StepUpVerifier;
use App\Domains\IdentityAccess\Services\TotpService;
use App\Domains\Platform\Config\PlatformConfig;
use App\Domains\Platform\Templates\DocumentPrintService;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Authentication (Chapter 04 §4.1, Chapter 14): username/email + password with lockout, optional
 * or mandatory TOTP (trusted devices), IP allow-list, concurrent-session cap, step-up for critical
 * permissions, password change/expiry and self-service reset. Errors never reveal whether an
 * account exists.
 */
final class AuthController extends ApiController
{
    private const MFA_PENDING = 'auth.mfa_pending_account';

    private const DEVICE_COOKIE = 'trusted_device';

    public function __construct(
        private readonly PlatformConfig $config,
        private readonly PasswordPolicy $passwords,
        private readonly TotpService $totp,
        private readonly SessionManager $sessions,
        private readonly StepUpVerifier $stepUp,
        private readonly Access $access,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $identifier = mb_strtolower(trim((string) $request->input('identifier', $request->input('username', ''))));
        $password = (string) $request->input('password', '');
        if ($identifier === '' || $password === '') {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Enter your username and password.');
        }
        $this->assertIpAllowed($request);
        /** @var UserAccount|null $account */
        $account = UserAccount::query()->where('account_type', AccountType::HUMAN->value)
            ->where(fn ($q) => $q->whereRaw('lower(username) = ?', [$identifier])->orWhereRaw('lower(email) = ?', [$identifier]))->first();
        $invalid = fn () => DomainException::of(ErrorCode::UNAUTHENTICATED, 'The username or password is incorrect.');
        if ($account === null) {
            Hash::check($password, '$argon2id$v=19$m=65536,t=4,p=1$c29tZXNhbHQ$5Bd5V7m6/5qY9dzUvbKXHA');   // equalize timing
            SecurityEvent::log(SecurityEventType::LOGIN_FAILURE, null, $identifier);
            throw $invalid();
        }
        if ($account->locked_until !== null && $account->locked_until->isFuture()) {
            SecurityEvent::log(SecurityEventType::LOGIN_FAILURE, $account->id, $identifier, ['reason' => 'locked']);
            throw DomainException::of(ErrorCode::UNAUTHENTICATED, 'Too many failed attempts. Try again later or contact your administrator.');
        }
        if (! Hash::check($password, $account->password_hash)) {
            $failures = $account->failed_login_count + 1;
            $lock = $failures >= $this->config->int('identity.lockout.threshold');
            $account->forceFill(['failed_login_count' => $lock ? 0 : $failures,
                'locked_until' => $lock ? now()->addMinutes($this->config->int('identity.lockout.duration_minutes')) : $account->locked_until])->save();
            SecurityEvent::log($lock ? SecurityEventType::LOCKOUT : SecurityEventType::LOGIN_FAILURE, $account->id, $identifier);
            throw $invalid();
        }
        if ($account->account_status !== AccountStatus::ACTIVE) {
            SecurityEvent::log(SecurityEventType::LOGIN_FAILURE, $account->id, $identifier, ['reason' => $account->account_status->value]);
            throw DomainException::of(ErrorCode::UNAUTHENTICATED, 'This account is not active. Contact your administrator.');
        }
        $account->forceFill(['failed_login_count' => 0])->save();

        if ($this->totp->isEnrolled($account) && ! $this->trustedDevice($request, $account)) {
            $request->session()->put(self::MFA_PENDING, ['id' => $account->id, 'at' => time()]);

            return response()->json(['data' => ['status' => 'mfa_required']]);
        }

        return $this->completeLogin($request, $account);
    }

    public function mfa(Request $request): JsonResponse
    {
        $pending = $request->session()->get(self::MFA_PENDING);
        if (! is_array($pending) || time() - (int) $pending['at'] > 300) {
            throw DomainException::of(ErrorCode::UNAUTHENTICATED, 'Sign in again.');
        }
        $account = UserAccount::query()->findOrFail($pending['id']);
        if (! $this->totp->verify($account, (string) $request->input('code', ''))) {
            SecurityEvent::log(SecurityEventType::LOGIN_FAILURE, $account->id, null, ['reason' => 'mfa']);
            throw DomainException::of(ErrorCode::UNAUTHENTICATED, 'The verification code is incorrect.');
        }
        $request->session()->forget(self::MFA_PENDING);
        $response = $this->completeLogin($request, $account);
        if ($request->boolean('remember_device')) {
            $token = Str::random(64);
            DB::table('trusted_devices')->insert(['id' => (string) Str::uuid7(), 'user_account_id' => $account->id, 'token_hash' => hash('sha256', $token),
                'label' => mb_substr((string) $request->userAgent(), 0, 120), 'ip_address' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
                'expires_at' => now()->addDays($this->config->int('identity.mfa.trusted_device_days')), 'created_at' => now(), 'updated_at' => now()]);
            $response->withCookie(cookie(self::DEVICE_COOKIE, $token, $this->config->int('identity.mfa.trusted_device_days') * 1440, secure: $request->isSecure(), httpOnly: true, sameSite: 'lax'));
        }

        return $response;
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user instanceof UserAccount) {
            SecurityEvent::log(SecurityEventType::LOGOUT, $user->id);
        }
        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['data' => ['status' => 'signed_out']]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        if (! Hash::check((string) $request->input('current_password', ''), $actor->password_hash)) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Your current password is incorrect.', ['field' => 'current_password']);
        }
        $new = (string) $request->input('password', '');
        $this->passwords->assertAcceptable($new, $actor);
        DB::transaction(function () use ($actor, $new) {
            DB::table('account_credentials_history')->insert(['id' => (string) Str::uuid7(), 'user_account_id' => $actor->id, 'password_hash' => $actor->password_hash, 'created_at' => now()]);
            $actor->forceFill(['password_hash' => $this->passwords->hash($new), 'password_changed_at' => now(), 'must_change_password' => false])->save();
        });
        SecurityEvent::log(SecurityEventType::PASSWORD_CHANGED, $actor->id);
        $this->sessions->revokeAll($actor, keepCurrent: true);

        return response()->json(['data' => ['status' => 'password_changed']]);
    }

    public function beginMfa(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->totp->beginEnrollment($this->actor($request))]);
    }

    public function confirmMfa(Request $request): JsonResponse
    {
        $codes = $this->totp->confirmEnrollment($this->actor($request), (string) $request->input('code', ''));
        if ($codes === null) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'The code is incorrect; scan the QR code again and retry.', ['field' => 'code']);
        }
        $this->actor($request)->forceFill(['mfa_status' => MfaStatus::ENROLLED])->save();

        return response()->json(['data' => ['recovery_codes' => $codes]]);
    }

    /** Step-up for critical operations: TOTP (or password when MFA is off). */
    public function stepUp(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $ok = $this->stepUp->factor() === 'mfa'
            ? $this->totp->verify($actor, (string) $request->input('code', ''))
            : Hash::check((string) $request->input('password', ''), $actor->password_hash);
        if (! $ok) {
            throw DomainException::of(ErrorCode::STEP_UP_REQUIRED, 'Verification failed.');
        }
        $this->stepUp->mark($request);
        SecurityEvent::log(SecurityEventType::MFA_STEP_UP, $actor->id);

        return response()->json(['data' => ['status' => 'verified', 'valid_minutes' => $this->config->int('security.step_up.validity_minutes')]]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $identifier = mb_strtolower(trim((string) $request->input('identifier', '')));
        if ($this->config->bool('identity.self_service_password_reset') && $identifier !== '') {
            $account = UserAccount::query()->where('account_type', AccountType::HUMAN->value)->where('account_status', AccountStatus::ACTIVE->value)
                ->where(fn ($q) => $q->whereRaw('lower(username) = ?', [$identifier])->orWhereRaw('lower(email) = ?', [$identifier]))->first();
            if ($account?->email) {
                $token = Str::random(64);
                DB::table('password_reset_tokens')->updateOrInsert(['email' => mb_strtolower($account->email)], ['token' => Hash::make($token), 'created_at' => now()]);
                $url = url('/password/reset?token='.$token.'&email='.urlencode($account->email));
                $body = app(DocumentPrintService::class)->renderer()->render((string) DB::table('templates')->where('code', 'email.auth.password_reset')
                    ->where('is_active', true)->value('body'), ['recipient_name' => $account->displayName(), 'reset_url' => $url, 'expires_minutes' => 60,
                        'brand' => app(\App\Domains\Platform\Services\BrandResolver::class)->publicProfile()]);
                try {
                    Mail::html($body, fn ($m) => $m->to($account->email)->subject('Password reset'));
                } catch (\Throwable) {
                    // delivery problems are not disclosed to the requester
                }
                SecurityEvent::log(SecurityEventType::PASSWORD_RESET_REQUESTED, $account->id);
            }
        }

        return response()->json(['data' => ['status' => 'if_the_account_exists_an_email_was_sent']], 202);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $email = mb_strtolower(trim((string) $request->input('email', '')));
        $row = DB::table('password_reset_tokens')->where('email', $email)->first();
        if ($row === null || now()->diffInMinutes($row->created_at, true) > 60 || ! Hash::check((string) $request->input('token', ''), $row->token)) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'This reset link is invalid or has expired.', ['field' => 'token']);
        }
        $account = UserAccount::query()->whereRaw('lower(email) = ?', [$email])->where('account_type', AccountType::HUMAN->value)->firstOrFail();
        $new = (string) $request->input('password', '');
        $this->passwords->assertAcceptable($new, $account);
        $account->forceFill(['password_hash' => $this->passwords->hash($new), 'password_changed_at' => now(), 'must_change_password' => false,
            'failed_login_count' => 0, 'locked_until' => null])->save();
        DB::table('password_reset_tokens')->where('email', $email)->delete();
        $this->sessions->revokeAll($account);
        SecurityEvent::log(SecurityEventType::PASSWORD_RESET_COMPLETED, $account->id);

        return response()->json(['data' => ['status' => 'password_reset']]);
    }

    private function completeLogin(Request $request, UserAccount $account): JsonResponse
    {
        Auth::guard('web')->login($account);
        $request->session()->regenerate();
        $account->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();
        $this->sessions->enforceConcurrentLimit($account, $request->session()->getId());
        SecurityEvent::log(SecurityEventType::LOGIN_SUCCESS, $account->id);
        $policy = MfaPolicy::from((string) $this->config->get('identity.mfa.policy'));
        $mfaRequired = ! $this->totp->isEnrolled($account) && ($policy === MfaPolicy::REQUIRED_FOR_ALL
            || ($account->is_super_admin && $this->config->bool('identity.mfa.required_for_platform_admins')));

        return response()->json(['data' => [
            'status' => 'authenticated',
            'must_change_password' => $account->must_change_password || $this->passwords->isExpired($account),
            'mfa_enrollment_required' => $mfaRequired,
        ]]);
    }

    private function trustedDevice(Request $request, UserAccount $account): bool
    {
        $token = $request->cookie(self::DEVICE_COOKIE);
        if (! is_string($token) || $token === '') {
            return false;
        }

        return DB::table('trusted_devices')->where('user_account_id', $account->id)->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now())->update(['last_seen_at' => now()]) > 0;
    }

    private function assertIpAllowed(Request $request): void
    {
        $ranges = array_values(array_filter((array) $this->config->list('security.ip_allowlist'), 'is_string'));
        if ($ranges !== [] && ! IpUtils::checkIp((string) $request->ip(), $ranges)) {
            SecurityEvent::log(SecurityEventType::ACCESS_DENIED, null, null, ['reason' => 'ip_allowlist', 'ip' => $request->ip()]);
            throw DomainException::of(ErrorCode::PERMISSION_DENIED, 'Sign-in is not allowed from this network.');
        }
    }
}
