<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Actions;

use App\Domains\IdentityAccess\Enums\AccountsPerPersonPolicy;
use App\Domains\IdentityAccess\Enums\AccountStatus;
use App\Domains\IdentityAccess\Enums\AccountType;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Platform\Config\SetConfigurationValueAction;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Switches identity.accounts_per_person (Chapter 04 §4.1). Switching to `single` is refused while
 * any person still has several active human accounts; the database marker that drives the partial
 * unique index is flipped in the same transaction as the configuration change.
 */
final class ChangeAccountsPerPersonPolicyAction
{
    public function __construct(private readonly SetConfigurationValueAction $setConfig) {}

    public function execute(AccountsPerPersonPolicy $policy, ?UserAccount $actor, ?string $reason = null): void
    {
        DB::transaction(function () use ($policy, $actor, $reason) {
            if ($policy === AccountsPerPersonPolicy::SINGLE) {
                $violations = DB::table('user_accounts')
                    ->where('account_type', AccountType::HUMAN->value)->where('account_status', '<>', AccountStatus::DEACTIVATED->value)
                    ->groupBy('person_id')->havingRaw('count(*) > 1')->pluck('person_id');
                if ($violations->isNotEmpty()) {
                    throw DomainException::of(ErrorCode::POLICY_VIOLATION,
                        'Some persons still have several active accounts; deactivate the extras before switching to one account per person.',
                        ['person_ids' => $violations->all()]);
                }
            }
            UserAccount::query()->update(['single_policy_marker' => $policy === AccountsPerPersonPolicy::SINGLE]);
            $this->setConfig->execute('identity.accounts_per_person', $policy->value, $actor, reason: $reason);
        });
    }
}
