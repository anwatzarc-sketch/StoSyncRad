<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Actions;

use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\IdentityAccess\Enums\AccountsPerPersonPolicy;
use App\Domains\IdentityAccess\Enums\AccountStatus;
use App\Domains\IdentityAccess\Enums\AccountType;
use App\Domains\IdentityAccess\Enums\MfaStatus;
use App\Domains\IdentityAccess\Enums\PersonStatus;
use App\Domains\IdentityAccess\Models\Person;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\IdentityAccess\Services\PasswordPolicy;
use App\Domains\Messaging\Services\Outbox;
use App\Domains\Platform\Config\PlatformConfig;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Creates a login account for a person, enforcing the corporate accounts-per-person policy
 * (Chapter 04 §4.1) under a lock on the person row; the partial unique index
 * user_accounts_single_human_per_person backs the `single` policy in the database.
 */
final class CreateUserAccountAction
{
    public function __construct(
        private readonly PlatformConfig $config,
        private readonly PasswordPolicy $passwords,
        private readonly AuditRecorder $audit,
        private readonly Outbox $outbox,
    ) {}

    /** @param  array<string, mixed>  $data  validated against UserAccountSchema */
    /**
     * `$customerPartnerId`/`$portal` are used only by the Customer Portal services (registration and
     * invitations); staff screens cannot create customer accounts.
     */
    public function execute(array $data, ?UserAccount $actor, bool $activate = true, bool $superAdmin = false, bool $portal = false, ?string $customerPartnerId = null): UserAccount
    {
        return DB::transaction(function () use ($data, $actor, $activate, $superAdmin, $portal, $customerPartnerId) {
            /** @var Person $person */
            $person = Person::query()->lockForUpdate()->findOrFail($data['person_id']);
            if ($person->status !== PersonStatus::ACTIVE) {
                throw DomainException::of(ErrorCode::POLICY_VIOLATION, 'Accounts can only be created for active persons.');
            }

            $type = AccountType::from($data['account_type'] ?? AccountType::HUMAN->value);
            if (($type === AccountType::CUSTOMER) !== $portal || ($type === AccountType::CUSTOMER && $superAdmin)) {
                throw DomainException::of(ErrorCode::POLICY_VIOLATION, $type === AccountType::CUSTOMER
                    ? 'Customer accounts are created through customer registration or invitations.'
                    : 'Only customer accounts are created by the customer portal.', ['field' => 'account_type']);
            }
            if ($type === AccountType::SERVICE && ! $this->config->bool('identity.service_accounts_enabled')) {
                throw DomainException::of(ErrorCode::FEATURE_DISABLED, 'Service accounts are disabled for this deployment.');
            }

            $policy = AccountsPerPersonPolicy::from((string) $this->config->get('identity.accounts_per_person'));
            if ($type === AccountType::HUMAN) {
                $existing = UserAccount::query()->where('person_id', $person->id)->where('account_type', AccountType::HUMAN->value)
                    ->where('account_status', '<>', AccountStatus::DEACTIVATED->value)->count();
                $limit = $policy === AccountsPerPersonPolicy::SINGLE ? 1 : $this->config->int('identity.max_accounts_per_person');
                if ($existing >= $limit) {
                    throw DomainException::of(ErrorCode::ACCOUNT_LIMIT_REACHED,
                        $policy === AccountsPerPersonPolicy::SINGLE
                            ? 'This person already has an account and the company policy allows one account per person.'
                            : "This person already has the maximum of {$limit} accounts.",
                        ['person_id' => $person->id, 'policy' => $policy->value, 'limit' => $limit]);
                }
            }

            $account = new UserAccount([
                'person_id' => $person->id,
                'username' => $data['username'],
                'email' => strtolower((string) $data['email']),
                'account_type' => $type,
                'must_change_password' => (bool) ($data['must_change_password'] ?? false),
                'customer_partner_id' => $type === AccountType::CUSTOMER ? $customerPartnerId : null,
                'created_by' => $actor?->id,
                'updated_by' => $actor?->id,
            ]);
            if ($type !== AccountType::SERVICE && ! empty($data['password'])) {
                $this->passwords->assertAcceptable((string) $data['password'], $account);
                $account->password_hash = $this->passwords->hash((string) $data['password']);
                $account->password_changed_at = now();
            }
            $account->forceFill([
                'account_status' => $activate ? AccountStatus::ACTIVE : AccountStatus::PENDING_ACTIVATION,
                'is_super_admin' => $superAdmin,
                'mfa_status' => MfaStatus::NOT_ENROLLED,
                'single_policy_marker' => $policy === AccountsPerPersonPolicy::SINGLE,
            ])->save();

            $this->audit->record($actor, 'identity.account.created', 'user_account', $account->id, null,
                ['username' => $account->username, 'email' => $account->email, 'account_type' => $type->value, 'person_id' => $person->id, 'is_super_admin' => $superAdmin]);
            $this->outbox->publish('identity.account.created', 'user_account', $account->id, ['person_id' => $person->id]);

            return $account;
        });
    }
}
