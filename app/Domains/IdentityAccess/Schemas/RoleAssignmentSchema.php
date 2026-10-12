<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Schemas;

use App\Domains\IdentityAccess\Enums\AssignmentScopeType;
use App\Domains\IdentityAccess\Models\Role;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Grant a role to an account at a scope. */
final class RoleAssignmentSchema extends TableSchema
{
    public static function table(): string
    {
        return 'role_assignments';
    }

    protected static function define(): array
    {
        return [
            'user_account_id' => Property::uuid()->required()->references(UserAccount::class)->virtual()->label('Account'),
            'role_id' => Property::uuid()->required()->references(Role::class)->virtual()->label('Role'),
            'scope_type' => Property::enum(AssignmentScopeType::class)->required()->virtual()->label('Scope'),
            'scope_id' => Property::uuid()->nullable()->virtual()->label('Branch / warehouse'),
            'effective_from' => Property::datetime()->nullable()->virtual()->label('Effective from'),
            'effective_to' => Property::datetime()->nullable()->after('effective_from')->virtual()->label('Effective to'),
            'reason' => Property::text(2000)->nullable()->trim()->virtual()->label('Reason'),
        ];
    }
}
