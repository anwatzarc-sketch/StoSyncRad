<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Change the validity window of a role assignment. */
final class RoleAssignmentUpdateSchema extends TableSchema
{
    public static function table(): string
    {
        return 'role_assignments';
    }

    protected static function define(): array
    {
        return [
            'effective_from' => Property::datetime()->nullable()->virtual()->label('Effective from'),
            'effective_to' => Property::datetime()->nullable()->after('effective_from')->virtual()->label('Effective to'),
            'reason' => Property::text(2000)->nullable()->trim()->virtual()->label('Reason'),
        ];
    }
}
