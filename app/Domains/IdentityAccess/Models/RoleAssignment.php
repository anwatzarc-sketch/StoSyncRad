<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Models;

use App\Domains\IdentityAccess\Enums\AssignmentScopeType;
use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Role granted to an account within a scope (Chapter 04 §4.6).
 *
 * @property string $user_account_id
 * @property string $role_id
 * @property AssignmentScopeType $scope_type
 * @property string|null $scope_id
 * @property \Carbon\CarbonImmutable|null $effective_from
 * @property \Carbon\CarbonImmutable|null $effective_to
 * @property string|null $assigned_by
 * @property string|null $reason
 */
final class RoleAssignment extends BaseModel
{
    protected $table = 'role_assignments';

    protected $fillable = ['user_account_id', 'role_id', 'scope_type', 'scope_id', 'effective_from', 'effective_to', 'assigned_by', 'reason'];

    protected function casts(): array
    {
        return [
            'scope_type' => AssignmentScopeType::class,
            'effective_from' => 'immutable_datetime',
            'effective_to' => 'immutable_datetime',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(UserAccount::class, 'user_account_id');
    }

    public function isEffective(): bool
    {
        $now = now();

        return ($this->effective_from === null || $this->effective_from->lte($now))
            && ($this->effective_to === null || $this->effective_to->gt($now));
    }
}
