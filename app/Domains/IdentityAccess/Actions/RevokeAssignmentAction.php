<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Actions;

use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\IdentityAccess\Models\RoleAssignment;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\IdentityAccess\Services\Access;
use App\Domains\IdentityAccess\Services\SessionManager;
use Illuminate\Support\Facades\DB;

/** Revokes a role assignment; effective on the account's next request (Chapter 04 §4.6). */
final class RevokeAssignmentAction
{
    public function __construct(private readonly Access $access, private readonly AuditRecorder $audit, private readonly SessionManager $sessions) {}

    public function execute(RoleAssignment $assignment, ?UserAccount $actor, ?string $reason = null): void
    {
        DB::transaction(function () use ($assignment, $actor, $reason) {
            $assignment = RoleAssignment::query()->with('role')->lockForUpdate()->findOrFail($assignment->id);
            $snapshot = ['user_account_id' => $assignment->user_account_id, 'role' => $assignment->role?->code,
                'scope_type' => $assignment->scope_type->value, 'scope_id' => $assignment->scope_id];
            $assignment->delete();
            $this->audit->record($actor, 'identity.assignment.revoked', 'role_assignment', $assignment->id, $snapshot, null, $reason);
            $this->access->forget($assignment->user_account_id);
            $account = UserAccount::query()->find($assignment->user_account_id);
            if ($account !== null) {
                $this->sessions->afterPrivilegeChange($account);
            }
        });
    }
}
