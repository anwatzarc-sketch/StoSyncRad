<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Services;

use App\Domains\IdentityAccess\Enums\MfaStatus;
use App\Domains\IdentityAccess\Enums\SecurityEventType;
use App\Domains\IdentityAccess\Models\MfaCredential;
use App\Domains\IdentityAccess\Models\SecurityEvent;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Platform\Services\BrandResolver;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * RFC 6238 TOTP multi-factor authentication with hashed single-use recovery codes and replay
 * protection (a code/time-step can be used once) — Chapter 04 §4.2.
 */
final class TotpService
{
    private Google2FA $engine;

    public function __construct(private readonly BrandResolver $brand)
    {
        $this->engine = new Google2FA;
    }

    /**
     * Start (or restart) enrollment: returns the secret, the otpauth URI and an SVG QR code.
     *
     * @return array{secret: string, otpauth_uri: string, qr_svg: string}
     */
    public function beginEnrollment(UserAccount $account): array
    {
        $secret = $this->engine->generateSecretKey(32);
        MfaCredential::query()->updateOrCreate(['user_account_id' => $account->id], [
            'secret' => $secret, 'recovery_code_hashes' => [], 'confirmed_at' => null, 'last_used_at' => null, 'last_used_counter' => null,
        ]);
        $issuer = $this->brand->publicProfile()['app_name'] ?? 'Stock';
        $uri = $this->engine->getQRCodeUrl($issuer, $account->email ?: $account->username, $secret);
        $svg = (new Writer(new ImageRenderer(new RendererStyle(220, 1), new SvgImageBackEnd)))->writeString($uri);

        return ['secret' => $secret, 'otpauth_uri' => $uri, 'qr_svg' => $svg];
    }

    /**
     * Confirm enrollment with a first valid code; returns plaintext recovery codes (shown once).
     *
     * @return array<int, string>|null null when the code is invalid
     */
    public function confirmEnrollment(UserAccount $account, string $code): ?array
    {
        return DB::transaction(function () use ($account, $code) {
            /** @var MfaCredential|null $credential */
            $credential = MfaCredential::query()->where('user_account_id', $account->id)->lockForUpdate()->first();
            if ($credential === null || ! $this->verifyAgainst($credential, $code)) {
                return null;
            }
            $codes = $this->newRecoveryCodes();
            $credential->forceFill([
                'confirmed_at' => now(),
                'recovery_code_hashes' => array_map(static fn (string $c) => Hash::make($c), $codes),
            ])->save();
            $account->forceFill(['mfa_status' => MfaStatus::ENROLLED])->save();
            SecurityEvent::log(SecurityEventType::MFA_ENROLLED, $account->id);

            return $codes;
        });
    }

    /** Verify a TOTP code or a recovery code for a challenge. */
    public function verify(UserAccount $account, string $code): bool
    {
        return DB::transaction(function () use ($account, $code) {
            /** @var MfaCredential|null $credential */
            $credential = MfaCredential::query()->where('user_account_id', $account->id)->whereNotNull('confirmed_at')->lockForUpdate()->first();
            if ($credential === null) {
                return false;
            }
            $normalized = preg_replace('/\s+/', '', $code) ?? '';
            if (preg_match('/^\d{6}$/', $normalized) === 1) {
                return $this->verifyAgainst($credential, $normalized);
            }

            // Recovery code: single use.
            $hashes = $credential->recovery_code_hashes ?? [];
            foreach ($hashes as $i => $hash) {
                if (Hash::check(strtoupper($normalized), $hash)) {
                    unset($hashes[$i]);
                    $credential->forceFill(['recovery_code_hashes' => array_values($hashes), 'last_used_at' => now()])->save();
                    SecurityEvent::log(SecurityEventType::MFA_STEP_UP, $account->id, null, ['method' => 'recovery_code', 'remaining' => count($hashes)]);

                    return true;
                }
            }

            return false;
        });
    }

    public function reset(UserAccount $account): void
    {
        MfaCredential::query()->where('user_account_id', $account->id)->delete();
        $account->forceFill(['mfa_status' => MfaStatus::NOT_ENROLLED])->save();
        DB::table('trusted_devices')->where('user_account_id', $account->id)->delete();
        SecurityEvent::log(SecurityEventType::MFA_RESET, $account->id);
    }

    /**
     * A candidate secret for replacing an enrolled authenticator. Nothing is stored: the caller keeps
     * the secret (e.g. in the session) until replace() confirms it, so the current authenticator keeps
     * working if the change is abandoned.
     *
     * @return array{secret: string, otpauth_uri: string, qr_svg: string}
     */
    public function candidate(UserAccount $account): array
    {
        $secret = $this->engine->generateSecretKey(32);
        $uri = $this->engine->getQRCodeUrl($this->brand->publicProfile()['app_name'] ?? 'Stock', $account->email ?: $account->username, $secret);

        return ['secret' => $secret, 'otpauth_uri' => $uri, 'qr_svg' => (new Writer(new ImageRenderer(new RendererStyle(220, 1), new SvgImageBackEnd)))->writeString($uri)];
    }

    /**
     * Switch to a new authenticator once its first code is valid; returns new recovery codes (the old
     * ones stop working). Null when the code does not match the candidate secret.
     *
     * @return array<int, string>|null
     */
    public function replace(UserAccount $account, string $secret, string $code): ?array
    {
        if ($this->engine->verifyKey($secret, preg_replace('/\s+/', '', $code) ?? '', 1) === false) {
            return null;
        }
        $codes = $this->newRecoveryCodes();
        MfaCredential::query()->updateOrCreate(['user_account_id' => $account->id], [
            'secret' => $secret, 'recovery_code_hashes' => array_map(static fn (string $c) => Hash::make($c), $codes),
            'confirmed_at' => now(), 'last_used_at' => now(), 'last_used_counter' => $this->engine->getTimestamp(),   // the confirming code cannot be replayed
        ]);
        $account->forceFill(['mfa_status' => MfaStatus::ENROLLED])->save();
        DB::table('trusted_devices')->where('user_account_id', $account->id)->delete();
        SecurityEvent::log(SecurityEventType::MFA_ENROLLED, $account->id, null, ['replaced' => true]);

        return $codes;
    }

    public function isEnrolled(UserAccount $account): bool
    {
        return MfaCredential::query()->where('user_account_id', $account->id)->whereNotNull('confirmed_at')->exists();
    }

    /** Verify a 6-digit code, refusing reuse of the same or an earlier time step. */
    private function verifyAgainst(MfaCredential $credential, string $code): bool
    {
        $timestamp = $this->engine->verifyKeyNewer($credential->secret, $code, $credential->last_used_counter ?? 0, 1);
        if ($timestamp === false || $timestamp === true) {
            // true is returned only when no previous timestamp is tracked; resolve the step explicitly.
            if ($timestamp === false) {
                return false;
            }
            $timestamp = $this->engine->getTimestamp();
        }
        $credential->forceFill(['last_used_counter' => (int) $timestamp, 'last_used_at' => now()])->save();

        return true;
    }

    /** @return array<int, string> */
    private function newRecoveryCodes(): array
    {
        return array_map(static fn () => strtoupper(Str::random(5).'-'.Str::random(5)), range(1, 10));
    }
}
