<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Services;

use App\Domains\IdentityAccess\Enums\AssignmentScopeType;
use App\Domains\IdentityAccess\Enums\PermissionScopeKind;
use App\Domains\IdentityAccess\Enums\RoleStatus;
use App\Domains\IdentityAccess\Models\RoleAssignment;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\IdentityAccess\Permissions\PermissionRegistry;
use App\Domains\Platform\Config\PlatformConfig;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Effective-permission resolver (Chapter 04 §4.6).
 *
 * Effective permission = union over active assignments of (role permissions ∪ implications),
 * each tagged with the assignment's scope. A warehouse-scoped permission granted at company
 * scope applies everywhere; at branch scope to that branch's warehouses; at warehouse scope
 * to that warehouse only. Resolution is memoized per account per request only.
 */
final class Access
{
    /** @var array<string, array{codes: array<string, array<int, array{type: string, id: ?string}>>, constraints: array<string, array<int, array<string,mixed>>>, company: bool, branches: array<int,string>, warehouses: array<int,string>}> */
    private array $memo = [];

    /** @var array<string, string|null>|null warehouse id → branch id */
    private ?array $warehouseBranches = null;

    public function __construct(private readonly PermissionRegistry $registry, private readonly PlatformConfig $config) {}

    /**
     * @param  Model|string|array{warehouse_id?: ?string, branch_id?: ?string}|null  $scope
     *         A Warehouse/Branch model, a warehouse id, or an explicit scope array. Null means
     *         "in any scope" (used by the route middleware before the target is resolved).
     */
    public function can(UserAccount $account, string $permission, Model|string|array|null $scope = null): bool
    {
        if (! $this->accountMayAct($account)) {
            return false;
        }
        $definition = $this->registry->get($permission);
        if ($definition === null) {
            return false;
        }
        if ($definition->featureFlag !== null && ! $this->config->feature($definition->featureFlag)) {
            return false;
        }
        if ($account->is_super_admin) {
            return true;
        }

        $grants = $this->effective($account)['codes'][$permission] ?? [];
        if ($grants === []) {
            return false;
        }

        [$warehouseId, $branchId] = $this->resolveScope($scope);

        return match ($definition->scopeKind) {
            PermissionScopeKind::GLOBAL, PermissionScopeKind::OWN_RECORDS => true,
            PermissionScopeKind::WAREHOUSE => $warehouseId === null
                ? true
                : $this->grantsCoverWarehouse($grants, $warehouseId, $branchId ?? $this->branchOfWarehouse($warehouseId)),
            PermissionScopeKind::BRANCH => $branchId === null && $warehouseId === null
                ? true
                : $this->grantsCoverBranch($grants, $branchId ?? $this->branchOfWarehouse((string) $warehouseId)),
        };
    }

    /** True when the actor holds the permission in at least one scope. */
    public function hasPermissionAnywhere(UserAccount $account, string $permission): bool
    {
        return $this->can($account, $permission, null);
    }

    /**
     * Every permission code the account holds in any scope (for the UI's permission-aware rendering).
     *
     * @return array<int, string>
     */
    public function permissionCodes(UserAccount $account): array
    {
        if (! $this->accountMayAct($account)) {
            return [];
        }
        if ($account->is_super_admin) {
            return $this->registry->all()->keys()->all();
        }

        return array_keys($this->effective($account)['codes']);
    }

    /**
     * Warehouse ids where the account holds a warehouse-scoped permission; '*' for all.
     *
     * @return array<int, string>|'*'
     */
    public function warehouseIdsFor(UserAccount $account, string $permission): array|string
    {
        if (! $this->accountMayAct($account)) {
            return [];
        }
        if ($account->is_super_admin) {
            return '*';
        }
        $grants = $this->effective($account)['codes'][$permission] ?? [];
        $ids = [];
        foreach ($grants as $grant) {
            if ($grant['type'] === AssignmentScopeType::COMPANY->value) {
                return '*';
            }
            if ($grant['type'] === AssignmentScopeType::WAREHOUSE->value && $grant['id'] !== null) {
                $ids[$grant['id']] = true;
            }
            if ($grant['type'] === AssignmentScopeType::BRANCH->value && $grant['id'] !== null) {
                foreach ($this->warehouseBranches() as $warehouseId => $branchId) {
                    if ($branchId === $grant['id']) {
                        $ids[$warehouseId] = true;
                    }
                }
            }
        }

        return array_keys($ids);
    }

    /**
     * Merged per-permission constraints (e.g. approval limits). Returns the most permissive
     * value per constraint key across the account's roles; null means unconstrained.
     *
     * @return array<string, mixed>
     */
    public function constraints(UserAccount $account, string $permission): array
    {
        if ($account->is_super_admin) {
            return [];
        }
        $sets = $this->effective($account)['constraints'][$permission] ?? [];
        if ($sets === []) {
            return [];
        }
        $merged = [];
        foreach ($sets as $set) {
            if ($set === []) {
                return []; // one unconstrained grant lifts all limits
            }
            foreach ($set as $k => $v) {
                $merged[$k] = isset($merged[$k]) && is_numeric($merged[$k]) && is_numeric($v)
                    ? (bccomp((string) $v, (string) $merged[$k], 10) > 0 ? (string) $v : (string) $merged[$k])
                    : $v;
            }
        }

        return $merged;
    }

    /** @return array{company: bool, branches: array<int,string>, warehouses: array<int,string>} */
    public function scopeSummary(UserAccount $account): array
    {
        if ($account->is_super_admin) {
            return ['company' => true, 'branches' => [], 'warehouses' => []];
        }
        $e = $this->effective($account);

        return ['company' => $e['company'], 'branches' => $e['branches'], 'warehouses' => $e['warehouses']];
    }

    public function forget(?string $accountId = null): void
    {
        if ($accountId === null) {
            $this->memo = [];
        } else {
            unset($this->memo[$accountId]);
        }
        $this->warehouseBranches = null;
    }

    // ---- internals ---------------------------------------------------------------------------

    private function accountMayAct(UserAccount $account): bool
    {
        return $account->isActive();
    }

    /** @return array{codes: array<string, array<int, array{type: string, id: ?string}>>, constraints: array<string, array<int, array<string,mixed>>>, company: bool, branches: array<int,string>, warehouses: array<int,string>} */
    private function effective(UserAccount $account): array
    {
        if (isset($this->memo[$account->id])) {
            return $this->memo[$account->id];
        }

        $codes = [];
        $constraints = [];
        $company = false;
        $branches = [];
        $warehouses = [];

        $assignments = RoleAssignment::query()
            ->with(['role', 'role.permissions'])
            ->where('user_account_id', $account->id)
            ->get();

        foreach ($assignments as $assignment) {
            if (! $assignment->isEffective() || $assignment->role === null || $assignment->role->status !== RoleStatus::ACTIVE) {
                continue;
            }
            $scope = ['type' => $assignment->scope_type->value, 'id' => $assignment->scope_id];
            match ($assignment->scope_type) {
                AssignmentScopeType::COMPANY => $company = true,
                AssignmentScopeType::BRANCH => $branches[$assignment->scope_id] = true,
                AssignmentScopeType::WAREHOUSE => $warehouses[$assignment->scope_id] = true,
            };

            foreach ($assignment->role->permissions as $rolePermission) {
                foreach ($this->registry->expand([$rolePermission->permission_code]) as $code) {
                    $codes[$code][] = $scope;
                    $constraints[$code][] = $code === $rolePermission->permission_code ? ($rolePermission->constraints ?? []) : [];
                }
            }
        }

        return $this->memo[$account->id] = [
            'codes' => $codes,
            'constraints' => $constraints,
            'company' => $company,
            'branches' => array_keys($branches),
            'warehouses' => array_keys($warehouses),
        ];
    }

    /** @return array{0: ?string, 1: ?string} [warehouseId, branchId] */
    private function resolveScope(Model|string|array|null $scope): array
    {
        if ($scope === null) {
            return [null, null];
        }
        if (is_string($scope)) {
            return [$scope, null];
        }
        if (is_array($scope)) {
            return [$scope['warehouse_id'] ?? null, $scope['branch_id'] ?? null];
        }
        if ($scope->getTable() === 'warehouses') {
            return [(string) $scope->getKey(), $scope->getAttribute('branch_id')];
        }
        if ($scope->getTable() === 'branches') {
            return [null, (string) $scope->getKey()];
        }
        $warehouseId = $scope->getAttribute('warehouse_id');
        $branchId = $scope->getAttribute('branch_id');

        return [$warehouseId !== null ? (string) $warehouseId : null, $branchId !== null ? (string) $branchId : null];
    }

    /** @param  array<int, array{type: string, id: ?string}>  $grants */
    private function grantsCoverWarehouse(array $grants, string $warehouseId, ?string $branchId): bool
    {
        foreach ($grants as $grant) {
            if ($grant['type'] === AssignmentScopeType::COMPANY->value) {
                return true;
            }
            if ($grant['type'] === AssignmentScopeType::WAREHOUSE->value && $grant['id'] === $warehouseId) {
                return true;
            }
            if ($grant['type'] === AssignmentScopeType::BRANCH->value && $branchId !== null && $grant['id'] === $branchId) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<int, array{type: string, id: ?string}>  $grants */
    private function grantsCoverBranch(array $grants, ?string $branchId): bool
    {
        foreach ($grants as $grant) {
            if ($grant['type'] === AssignmentScopeType::COMPANY->value) {
                return true;
            }
            if ($grant['type'] === AssignmentScopeType::BRANCH->value && $branchId !== null && $grant['id'] === $branchId) {
                return true;
            }
        }

        return false;
    }

    private function branchOfWarehouse(string $warehouseId): ?string
    {
        return $this->warehouseBranches()[$warehouseId] ?? null;
    }

    /** @return array<string, string|null> */
    private function warehouseBranches(): array
    {
        if ($this->warehouseBranches !== null) {
            return $this->warehouseBranches;
        }
        try {
            return $this->warehouseBranches = DB::table('warehouses')->pluck('branch_id', 'id')->map(static fn ($v) => $v === null ? null : (string) $v)->all();
        } catch (\Throwable) {
            return $this->warehouseBranches = [];
        }
    }
}
