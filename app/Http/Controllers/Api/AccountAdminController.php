<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\IdentityAccess\Actions\ArchiveRoleAction;
use App\Domains\IdentityAccess\Actions\AssignRoleAction;
use App\Domains\IdentityAccess\Actions\ChangeAccountsPerPersonPolicyAction;
use App\Domains\IdentityAccess\Actions\RevokeAssignmentAction;
use App\Domains\IdentityAccess\Actions\SaveRoleAction;
use App\Domains\IdentityAccess\Enums\AccountStatus;
use App\Domains\IdentityAccess\Enums\AccountsPerPersonPolicy;
use App\Domains\IdentityAccess\Enums\MfaStatus;
use App\Domains\IdentityAccess\Enums\SecurityEventType;
use App\Domains\IdentityAccess\Models\Role;
use App\Domains\IdentityAccess\Models\RoleAssignment;
use App\Domains\IdentityAccess\Models\SecurityEvent;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\IdentityAccess\Services\Access;
use App\Domains\IdentityAccess\Services\SessionManager;
use App\Domains\IdentityAccess\Services\TotpService;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Account and role administration beyond plain CRUD (Chapter 04): role assignment by scope,
 * revocation, account lifecycle (lock/suspend/activate/deactivate), MFA reset, session revocation,
 * forced password change, role clone/archive and the accounts-per-person policy. Privilege changes
 * invalidate sessions and the permission cache immediately.
 */
final class AccountAdminController extends ApiController
{
    public function __construct(private readonly Access $access, private readonly SessionManager $sessions, private readonly AuditRecorder $audit) {}

    public function assign(Request $request, AssignRoleAction $assign): JsonResponse
    {
        $assignment = $assign->execute($request->only(['user_account_id', 'role_id', 'scope_type', 'scope_id', 'effective_from', 'effective_to', 'reason']), $this->actor($request));
        $this->afterPrivilegeChange($assignment->user_account_id);

        return $this->respond($assignment, 201);
    }

    public function revoke(Request $request, RevokeAssignmentAction $revoke, string $id): JsonResponse
    {
        $assignment = RoleAssignment::query()->findOrFail($id);
        $revoke->execute($assignment, $this->actor($request), $request->input('reason'));
        $this->afterPrivilegeChange($assignment->user_account_id);

        return response()->json(['data' => ['status' => 'revoked']]);
    }

    public function setStatus(Request $request, string $id): JsonResponse
    {
        $actor = $this->actor($request);
        $target = UserAccount::query()->findOrFail($id);
        $status = AccountStatus::tryFrom((string) ($request->route()->getAction('variant') ?? $request->input('status'))) ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED,
            'Unknown account status. Allowed: '.implode(', ', AccountStatus::values()).'.', ['field' => 'status']);
        $permission = match ($status) {
            AccountStatus::ACTIVE => 'identity.account.activate', AccountStatus::LOCKED => 'identity.account.lock',
            AccountStatus::SUSPENDED => 'identity.account.suspend', AccountStatus::DEACTIVATED => 'identity.account.deactivate',
            AccountStatus::PENDING_ACTIVATION => throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Accounts cannot be returned to pending activation.'),
        };
        if (! $this->access->can($actor, $permission)) {
            throw DomainException::permissionDenied($permission);
        }
        if ($target->id === $actor->id && $status !== AccountStatus::ACTIVE) {
            throw DomainException::of(ErrorCode::POLICY_VIOLATION, 'You cannot lock or deactivate your own account.');
        }
        if ($target->is_super_admin && ! $actor->is_super_admin) {
            throw DomainException::of(ErrorCode::PERMISSION_DENIED, 'Only a super administrator can change another super administrator.');
        }
        $before = $target->account_status->value;
        $target->forceFill(['account_status' => $status, 'locked_until' => null, 'failed_login_count' => 0,
            'deactivated_at' => $status === AccountStatus::DEACTIVATED ? now() : null, 'deactivated_by' => $status === AccountStatus::DEACTIVATED ? $actor->id : null])->save();
        if ($status !== AccountStatus::ACTIVE) {
            $this->sessions->revokeAll($target, includeTokens: true);
            SecurityEvent::log($status === AccountStatus::DEACTIVATED ? SecurityEventType::ACCOUNT_DEACTIVATED : SecurityEventType::ACCOUNT_LOCKED, $target->id);
        }
        $this->audit->record($actor, 'identity.account.status_changed', 'user_accounts', $target->id, ['status' => $before], ['status' => $status->value], $request->input('reason'));

        return $this->respond($target);
    }

    public function resetMfa(Request $request, TotpService $totp, string $id): JsonResponse
    {
        $target = UserAccount::query()->findOrFail($id);
        $totp->reset($target);
        $target->forceFill(['mfa_status' => MfaStatus::NOT_ENROLLED])->save();
        $this->sessions->revokeAll($target, includeTokens: false);
        SecurityEvent::log(SecurityEventType::MFA_RESET, $target->id);
        $this->audit->record($this->actor($request), 'identity.account.mfa_reset', 'user_accounts', $target->id, null, null, $request->input('reason'));

        return response()->json(['data' => ['status' => 'mfa_reset']]);
    }

    public function revokeSessions(Request $request, string $id): JsonResponse
    {
        $target = UserAccount::query()->findOrFail($id);
        $count = $this->sessions->revokeAll($target, includeTokens: $request->boolean('include_tokens'));
        $this->audit->record($this->actor($request), 'identity.account.sessions_revoked', 'user_accounts', $target->id, null, ['count' => $count]);

        return response()->json(['data' => ['revoked' => $count]]);
    }

    public function forcePasswordChange(Request $request, string $id): JsonResponse
    {
        $target = UserAccount::query()->findOrFail($id);
        $target->forceFill(['must_change_password' => true])->save();
        $this->audit->record($this->actor($request), 'identity.account.password_change_forced', 'user_accounts', $target->id);

        return response()->json(['data' => ['status' => 'password_change_required']]);
    }

    public function cloneRole(Request $request, SaveRoleAction $save, string $id): JsonResponse
    {
        $source = Role::query()->findOrFail($id);
        $code = (string) $request->input('code', $source->code.'_copy');
        $role = $save->execute(['code' => $code, 'name' => (string) $request->input('name', $source->name.' (copy)'), 'description' => $source->description,
            'permissions' => $source->permissions()->pluck('permission_code')->all()], $this->actor($request));

        return $this->respond($role, 201);
    }

    public function archiveRole(Request $request, ArchiveRoleAction $archive, string $id): JsonResponse
    {
        return $this->respond($archive->execute(Role::query()->findOrFail($id), $this->actor($request)));
    }

    public function accountsPolicy(Request $request, ChangeAccountsPerPersonPolicyAction $change): JsonResponse
    {
        $policy = AccountsPerPersonPolicy::tryFrom((string) $request->input('policy')) ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED,
            'Allowed: '.implode(', ', AccountsPerPersonPolicy::values()).'.', ['field' => 'policy']);
        $change->execute($policy, $this->actor($request), $request->input('reason'));

        return response()->json(['data' => ['policy' => $policy->value]]);
    }

    private function afterPrivilegeChange(string $accountId): void
    {
        $this->access->forget($accountId);
        $account = UserAccount::query()->find($accountId);
        if ($account !== null) {
            $this->sessions->afterPrivilegeChange($account);
        }
    }
}
