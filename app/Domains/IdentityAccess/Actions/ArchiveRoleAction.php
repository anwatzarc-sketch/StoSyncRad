<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Actions;

use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\IdentityAccess\Enums\RoleStatus;
use App\Domains\IdentityAccess\Models\Role;
use App\Domains\IdentityAccess\Models\RoleAssignment;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\IdentityAccess\Services\Access;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Illuminate\Support\Facades\DB;

/** Archives a role; refused while it is still assigned (Chapter 04 §4.5). */
final class ArchiveRoleAction
{
    public function __construct(private readonly AuditRecorder $audit, private readonly Access $access) {}

    public function execute(Role $role, ?UserAccount $actor): Role
    {
        return DB::transaction(function () use ($role, $actor) {
            $role = Role::query()->lockForUpdate()->findOrFail($role->id);
            if ($role->is_system) {
                throw DomainException::of(ErrorCode::POLICY_VIOLATION, 'System roles cannot be archived.');
            }
            $assigned = RoleAssignment::query()->where('role_id', $role->id)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', now()))->count();
            if ($assigned > 0) {
                throw DomainException::of(ErrorCode::POLICY_VIOLATION, "The role is still assigned to {$assigned} account(s); revoke the assignments first.",
                    ['active_assignments' => $assigned]);
            }
            $role->forceFill(['status' => RoleStatus::ARCHIVED, 'updated_by' => $actor?->id])->save();
            $this->audit->record($actor, 'identity.role.archived', 'role', $role->id);
            $this->access->forget();

            return $role;
        });
    }
}
