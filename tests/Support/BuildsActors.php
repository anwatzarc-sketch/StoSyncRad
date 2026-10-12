<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domains\IdentityAccess\Actions\AssignRoleAction;
use App\Domains\IdentityAccess\Actions\SaveRoleAction;
use App\Domains\IdentityAccess\Enums\AccountStatus;
use App\Domains\IdentityAccess\Enums\AccountType;
use App\Domains\IdentityAccess\Enums\MfaStatus;
use App\Domains\IdentityAccess\Enums\PersonStatus;
use App\Domains\IdentityAccess\Models\Person;
use App\Domains\IdentityAccess\Models\Role;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\IdentityAccess\Permissions\PermissionSyncer;
use App\Domains\IdentityAccess\Services\Access;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

trait BuildsActors
{
    private static bool $permissionsSynced = false;

    protected function syncPermissions(): void
    {
        app(PermissionSyncer::class)->sync();
    }

    protected function account(bool $superAdmin = false, string $password = 'Correct-Horse-9'): UserAccount
    {
        $person = new Person(['full_name' => 'Test '.Str::random(6)]);
        $person->forceFill(['status' => PersonStatus::ACTIVE, 'email' => Str::lower(Str::random(10)).'@example.test'])->save();
        $account = new UserAccount([
            'person_id' => $person->id, 'username' => 'u'.Str::lower(Str::random(10)), 'email' => $person->email,
            'account_type' => AccountType::HUMAN, 'password_hash' => Hash::make($password),
        ]);
        $account->forceFill(['account_status' => AccountStatus::ACTIVE, 'is_super_admin' => $superAdmin, 'mfa_status' => MfaStatus::NOT_ENROLLED,
            'single_policy_marker' => false])->save();

        return $account;
    }

    /**
     * Account holding a role with the given permission codes in a scope.
     *
     * @param  array<int, string>  $permissions
     */
    protected function actorWith(array $permissions, string $scopeType = 'company', ?string $scopeId = null): UserAccount
    {
        $this->syncPermissions();
        $role = app(SaveRoleAction::class)->execute(['code' => 'r_'.Str::lower(Str::random(8)), 'name' => 'Test role', 'permissions' => $permissions], null);
        $account = $this->account();
        app(AssignRoleAction::class)->execute(['user_account_id' => $account->id, 'role_id' => $role->id, 'scope_type' => $scopeType, 'scope_id' => $scopeId], null);
        app(Access::class)->forget();

        return $account->refresh();
    }

    protected function superAdmin(): UserAccount
    {
        $this->syncPermissions();

        return $this->account(true);
    }
}
