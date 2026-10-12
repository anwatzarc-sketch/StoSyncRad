<?php

declare(strict_types=1);

namespace App\Domains\Platform\Setup\Steps;

use App\Domains\IdentityAccess\Actions\AssignRoleAction;
use App\Domains\IdentityAccess\Actions\CreateUserAccountAction;
use App\Domains\IdentityAccess\Actions\SaveRoleAction;
use App\Domains\IdentityAccess\Enums\PersonStatus;
use App\Domains\IdentityAccess\Models\Person;
use App\Domains\IdentityAccess\Models\Role;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\IdentityAccess\Permissions\PermissionRegistry;
use App\Domains\IdentityAccess\Permissions\PermissionSyncer;
use App\Domains\IdentityAccess\Schemas\RoleSchema;
use App\Domains\Platform\Enums\PlatformSetupStep;
use App\Domains\Platform\Seeds\RoleTemplatesSeed;
use App\Domains\Platform\Seeds\SeedRunner;
use App\Domains\Platform\Setup\SetupContext;
use App\Domains\Platform\Setup\SetupStepHandler;
use App\Support\Schema\SchemaValidator;
use App\Support\Schema\ValidationContext;
use Illuminate\Support\Facades\DB;

/**
 * Step 12 — permission sync, optional role templates, custom roles and initial accounts.
 * Payload: {
 *   templates: "all" | "none" | [template codes],
 *   roles: [{code, name, description?, permissions: [codes]}],
 *   accounts: [{full_name, email, username?, password, roles: [{role_code, scope_type, scope_id?}]}]
 * }
 */
final class RolesAccessStep implements SetupStepHandler
{
    public function __construct(
        private readonly PermissionSyncer $syncer,
        private readonly PermissionRegistry $registry,
        private readonly SeedRunner $seeds,
        private readonly SaveRoleAction $saveRole,
        private readonly CreateUserAccountAction $createAccount,
        private readonly AssignRoleAction $assign,
        private readonly SchemaValidator $validator,
    ) {}

    public function step(): PlatformSetupStep
    {
        return PlatformSetupStep::ROLES_ACCESS;
    }

    public function handle(array $payload, SetupContext $context): array
    {
        $sync = $this->syncer->sync();
        $templates = $payload['templates'] ?? 'all';
        if ($templates === 'all') {
            $this->seeds->run(RoleTemplatesSeed::class);
        } elseif (is_array($templates)) {
            $this->seeds->run(RoleTemplatesSeed::class, ['templates' => array_values($templates)]);
        }

        $result = DB::transaction(function () use ($payload, $context) {
            $roles = 0;
            foreach ((array) ($payload['roles'] ?? []) as $rolePayload) {
                $data = $this->validator->validate(RoleSchema::class, $rolePayload, new ValidationContext('create'));
                $existing = Role::query()->where('code', $data['code'])->first();
                $this->saveRole->execute($data, $context->actor, $existing);
                $roles++;
            }
            $accounts = 0;
            foreach ((array) ($payload['accounts'] ?? []) as $accountPayload) {
                $account = UserAccount::query()->where('email', strtolower((string) $accountPayload['email']))->first();
                if ($account === null) {
                    $person = new Person(['full_name' => (string) $accountPayload['full_name'], 'email' => strtolower((string) $accountPayload['email'])]);
                    $person->forceFill(['status' => PersonStatus::ACTIVE])->save();
                    $account = $this->createAccount->execute(['person_id' => $person->id, 'username' => (string) ($accountPayload['username'] ?? $accountPayload['email']),
                        'email' => (string) $accountPayload['email'], 'password' => $accountPayload['password'] ?? null,
                        'must_change_password' => true], $context->actor);
                    $accounts++;
                }
                foreach ((array) ($accountPayload['roles'] ?? []) as $grant) {
                    $role = Role::query()->where('code', $grant['role_code'] ?? '')->firstOrFail();
                    $exists = $account->assignments()->where('role_id', $role->id)->where('scope_type', $grant['scope_type'] ?? 'company')
                        ->where('scope_id', $grant['scope_id'] ?? null)->exists();
                    if (! $exists) {
                        $this->assign->execute(['user_account_id' => $account->id, 'role_id' => $role->id, 'scope_type' => $grant['scope_type'] ?? 'company',
                            'scope_id' => $grant['scope_id'] ?? null, 'reason' => 'Platform Setup'], $context->actor);
                    }
                }
            }

            return ['roles_saved' => $roles, 'accounts_created' => $accounts];
        });

        $hasDelegatedAdmin = DB::table('role_assignments as a')->join('role_permissions as p', 'p.role_id', '=', 'a.role_id')
            ->join('user_accounts as u', 'u.id', '=', 'a.user_account_id')->where('u.is_super_admin', false)
            ->where('p.permission_code', 'platform.platform.manage')->exists();

        return $result + ['permissions' => $sync, 'warnings' => $hasDelegatedAdmin ? [] : [
            'No account other than the Super Admin holds platform.platform.manage. Consider assigning the Platform Administrator role to a second person.',
        ]];
    }

    public function current(): array
    {
        return [
            'templates' => array_map(fn ($code, $t) => ['code' => $code, 'name' => $t[0], 'description' => $t[1]], array_keys(RoleTemplatesSeed::templates()), RoleTemplatesSeed::templates()),
            'roles' => Role::query()->where('is_system', false)->orderBy('name')->get(['id', 'code', 'name', 'status'])->toArray(),
            'modules' => $this->registry->modules(),
            'permission_count' => $this->registry->all()->count(),
        ];
    }
}
