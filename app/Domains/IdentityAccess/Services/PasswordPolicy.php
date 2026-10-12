<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Services;

use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Platform\Config\PlatformConfig;
use App\Support\Validation\ValidationErrorCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Configurable password policy (Chapter 04 §4.2, identity.password.*).
 * Hashing uses the framework hasher configured for Argon2id.
 */
final class PasswordPolicy
{
    public function __construct(private readonly PlatformConfig $config) {}

    /**
     * @throws ValidationException
     */
    public function assertAcceptable(string $password, ?UserAccount $account = null, string $field = 'password'): void
    {
        $errors = $this->violations($password, $account);
        if ($errors !== []) {
            throw ValidationException::withMessages([$field => array_map(
                static fn (string $m) => ValidationErrorCode::FORMAT_INVALID->message($field, ['rule' => 'password_policy'], $m), $errors)]);
        }
    }

    /** @return array<int, string> human-readable violations */
    public function violations(string $password, ?UserAccount $account = null): array
    {
        $errors = [];
        $min = $this->config->int('identity.password.min_length');
        if (mb_strlen($password) < $min) {
            $errors[] = "Password must be at least {$min} characters.";
        }
        if (mb_strlen($password) > 128) {
            $errors[] = 'Password must not exceed 128 characters.';
        }
        $classes = (int) preg_match('/[a-z]/', $password) + (int) preg_match('/[A-Z]/', $password)
            + (int) preg_match('/\d/', $password) + (int) preg_match('/[^A-Za-z0-9]/', $password);
        $required = $this->config->int('identity.password.require_classes');
        if ($classes < $required) {
            $errors[] = "Password must contain at least {$required} of: lowercase, uppercase, digit, symbol.";
        }
        if ($account !== null) {
            foreach ([$account->username, $account->email] as $identity) {
                if ($identity !== null && $identity !== '' && mb_stripos($password, (string) strtok($identity, '@')) !== false) {
                    $errors[] = 'Password must not contain your username or email.';
                    break;
                }
            }
            $history = $this->config->int('identity.password.history');
            if ($history > 0) {
                $previous = DB::table('account_credentials_history')->where('user_account_id', $account->id)
                    ->orderByDesc('created_at')->limit($history)->pluck('password_hash');
                if ($account->password_hash !== null) {
                    $previous->prepend($account->password_hash);
                }
                foreach ($previous as $hash) {
                    if (Hash::check($password, $hash)) {
                        $errors[] = "Password must differ from your last {$history} passwords.";
                        break;
                    }
                }
            }
        }

        return $errors;
    }

    public function hash(string $password): string
    {
        return Hash::make($password);
    }

    public function isExpired(UserAccount $account): bool
    {
        $maxAge = $this->config->int('identity.password.max_age_days');

        return $maxAge > 0 && $account->password_changed_at !== null && $account->password_changed_at->addDays($maxAge)->isPast();
    }
}
