<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Actions;

use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\IdentityAccess\Enums\AccountStatus;
use App\Domains\IdentityAccess\Enums\AssignmentScopeType;
use App\Domains\IdentityAccess\Enums\RoleStatus;
use App\Domains\IdentityAccess\Models\Role;
use App\Domains\IdentityAccess\Models\RoleAssignment;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\IdentityAccess\Services\Access;
use App\Domains\IdentityAccess\Services\SessionManager;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Warehouse;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Grants a role to an account within a scope (Chapter 04 §4.6). An assigner may only grant scopes
 * they themselves cover for identity.assignment.create, unless they hold
 * identity.assignment.grant_any_scope. The super-admin role is never assignable here.
 */
final class AssignRoleAction
{
    public function __construct(
        private readonly Access $access,
        private readonly AuditRecorder $audit,
        private readonly SessionManager $sessions,
    ) {}

    /**
     * @param  array{user_account_id: string, role_id: string, scope_type: string, scope_id?: ?string, effective_from?: ?string, effective_to?: ?string, reason?: ?string}  $data
     */
    public function execute(array $data, ?UserAccount $actor): RoleAssignment
    {
        return DB::transaction(function () use ($data, $actor) {
            /** @var UserAccount $account */
            $account = UserAccount::query()->lockForUpdate()->findOrFail($data['user_account_id']);
            /** @var Role $role */
            $role = Role::query()->findOrFail($data['role_id']);
            $scope = AssignmentScopeType::from($data['scope_type']);
            $scopeId = $scope === AssignmentScopeType::COMPANY ? null : ($data['scope_id'] ?? null);

            if ($account->isCustomer()) {
                throw DomainException::of(ErrorCode::POLICY_VIOLATION, 'Customer portal accounts get their access from their customer membership, not from staff roles.');
            }
            if ($role->is_system || $role->code === Role::SUPER_ADMIN_CODE) {
                throw DomainException::of(ErrorCode::POLICY_VIOLATION, 'The super-administrator role cannot be assigned.');
            }
            if ($role->status !== RoleStatus::ACTIVE) {
                throw DomainException::of(ErrorCode::POLICY_VIOLATION, 'Only active roles can be assigned.');
            }
            if ($account->account_status === AccountStatus::DEACTIVATED) {
                throw DomainException::of(ErrorCode::POLICY_VIOLATION, 'Roles cannot be assigned to deactivated accounts.');
            }
            $target = $this->resolveScope($scope, $scopeId);
            if ($actor !== null && ! $actor->is_super_admin && ! $this->access->can($actor, 'identity.assignment.grant_any_scope')) {
                if (! $this->access->can($actor, 'identity.assignment.create', $target)) {
                    throw DomainException::of(ErrorCode::WAREHOUSE_SCOPE_DENIED, 'You can only grant roles within scopes you administer.',
                        ['scope_type' => $scope->value, 'scope_id' => $scopeId]);
                }
                if ($scope === AssignmentScopeType::COMPANY && ! $this->access->scopeSummary($actor)['company']) {
                    throw DomainException::of(ErrorCode::WAREHOUSE_SCOPE_DENIED, 'Only company-wide administrators can grant company-wide roles.');
                }
            }

            $duplicate = RoleAssignment::query()->where('user_account_id', $account->id)->where('role_id', $role->id)
                ->where('scope_type', $scope->value)->where('scope_id', $scopeId)->exists();
            if ($duplicate) {
                throw DomainException::of(ErrorCode::DUPLICATE_RESOURCE, 'This role is already assigned in this scope.');
            }

            $assignment = RoleAssignment::query()->create([
                'user_account_id' => $account->id,
                'role_id' => $role->id,
                'scope_type' => $scope,
                'scope_id' => $scopeId,
                'effective_from' => $data['effective_from'] ?? null,
                'effective_to' => $data['effective_to'] ?? null,
                'assigned_by' => $actor?->id,
                'reason' => $data['reason'] ?? null,
            ]);

            $this->audit->record($actor, 'identity.assignment.created', 'role_assignment', $assignment->id, null, [
                'user_account_id' => $account->id, 'role' => $role->code, 'scope_type' => $scope->value, 'scope_id' => $scopeId,
            ], $data['reason'] ?? null);
            $this->access->forget($account->id);
            $this->sessions->afterPrivilegeChange($account);

            return $assignment;
        });
    }

    /** @return array{warehouse_id?: ?string, branch_id?: ?string}|null */
    private function resolveScope(AssignmentScopeType $scope, ?string $scopeId): ?array
    {
        return match ($scope) {
            AssignmentScopeType::COMPANY => null,
            AssignmentScopeType::BRANCH => ['branch_id' => Branch::query()->findOrFail($scopeId)->id],
            AssignmentScopeType::WAREHOUSE => (function () use ($scopeId) {
                $wh = Warehouse::query()->findOrFail($scopeId);

                return ['warehouse_id' => $wh->id, 'branch_id' => $wh->branch_id];
            })(),
        };
    }
}
