<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Copy a role with its permissions. */
final class RoleCloneSchema extends TableSchema
{
    public static function table(): string
    {
        return 'roles';
    }

    protected static function define(): array
    {
        return [
            'code' => Property::string(64)->required()->trim()->pattern('/^[a-z][a-z0-9_]{1,63}$/')->virtual()->label('New role code'),
            'name' => Property::string(120)->required()->trim()->virtual()->label('New role name'),
        ];
    }
}
