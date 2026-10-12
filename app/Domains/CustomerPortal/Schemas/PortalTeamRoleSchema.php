<?php

declare(strict_types=1);

namespace App\Domains\CustomerPortal\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Make a colleague an administrator or a regular member. */
final class PortalTeamRoleSchema extends TableSchema
{
    public static function table(): string
    {
        return 'user_accounts';
    }

    protected static function define(): array
    {
        return [
            'administrator' => Property::boolean()->required()->virtual()->label('Administrator'),
        ];
    }
}
