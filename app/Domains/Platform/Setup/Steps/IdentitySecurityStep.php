<?php

declare(strict_types=1);

namespace App\Domains\Platform\Setup\Steps;

use App\Domains\IdentityAccess\Actions\ChangeAccountsPerPersonPolicyAction;
use App\Domains\IdentityAccess\Enums\AccountsPerPersonPolicy;
use App\Domains\IdentityAccess\Services\PasswordPolicy;
use App\Domains\Platform\Enums\PlatformSetupStep;
use App\Domains\Platform\Setup\ConfigApplier;
use App\Domains\Platform\Setup\SetupContext;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Validation\ValidationErrorCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Step 6 — identity and security policy, including the corporate accounts-per-person policy
 * (Chapter 02 §2.5 step 6). The Super Admin confirms with their current password. A new password
 * is optional — when given (with a matching confirmation) it replaces the current one — and becomes
 * required when the chosen policy rejects the current password. Everything is applied atomically:
 * a password error leaves the policy unchanged.
 *
 * Payload: {config: {identity.*, security.*}, current_password, new_password?, new_password_confirmation?}
 */
final class IdentitySecurityStep extends ConfigOnlyStep
{
    public function __construct(ConfigApplier $applier, private readonly PasswordPolicy $passwords, private readonly ChangeAccountsPerPersonPolicyAction $changePolicy)
    {
        parent::__construct($applier);
    }

    public function step(): PlatformSetupStep
    {
        return PlatformSetupStep::IDENTITY_SECURITY;
    }

    protected function prefixes(): array
    {
        return ['identity.', 'security.'];
    }

    public function handle(array $payload, SetupContext $context): array
    {
        $actor = $context->actor ?? throw DomainException::of(ErrorCode::UNAUTHENTICATED, 'Super Admin session required.');
        $config = (array) ($payload['config'] ?? []);
        $policy = isset($config['identity.accounts_per_person']) ? AccountsPerPersonPolicy::tryFrom((string) $config['identity.accounts_per_person']) : null;
        unset($config['identity.accounts_per_person']);

        $current = is_string($payload['current_password'] ?? null) ? $payload['current_password'] : '';
        if ($current === '') {
            self::fail('current_password', 'Enter your current password to confirm these security settings.');
        }
        if (! Hash::check($current, (string) $actor->password_hash)) {
            self::fail('current_password', 'The current password is not correct.');
        }
        $new = is_string($payload['new_password'] ?? null) && $payload['new_password'] !== '' ? $payload['new_password'] : null;
        if ($new !== null && array_key_exists('new_password_confirmation', $payload) && $payload['new_password_confirmation'] !== $new) {
            self::fail('new_password_confirmation', 'The new password and its confirmation do not match.');
        }

        return DB::transaction(function () use ($config, $policy, $actor, $current, $new) {
            $applied = $this->applier->apply($config, $this->prefixes(), $actor);
            if ($policy !== null) {
                $this->changePolicy->execute($policy, $actor, 'Platform Setup');
            }
            // The current password against the policy just chosen (length and character classes).
            $violations = $this->passwords->violations($current);
            if ($violations !== [] && $new === null) {
                self::fail('new_password', 'Your current password does not meet the new policy — '.implode(' ', $violations).' Choose a new password.');
            }
            if ($new !== null) {
                $this->passwords->assertAcceptable($new, $actor, 'new_password');
                DB::table('account_credentials_history')->insert(['id' => (string) Str::uuid7(), 'user_account_id' => $actor->id,
                    'password_hash' => $actor->password_hash, 'created_at' => now()]);
                $actor->forceFill(['password_hash' => $this->passwords->hash($new), 'password_changed_at' => now(), 'must_change_password' => false])->save();
            }

            return ['applied' => array_keys($applied), 'password_rotated' => $new !== null];
        });
    }

    /** Field-level error so the wizard can show it next to the right input. */
    private static function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [ValidationErrorCode::CUSTOM->message($field, [], $message)]]);
    }
}
