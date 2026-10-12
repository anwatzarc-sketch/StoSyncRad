<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Change own password. */
final class ChangePasswordSchema extends TableSchema
{
    public static function table(): string
    {
        return 'user_accounts';
    }

    protected static function define(): array
    {
        return [
            'current_password' => Property::string(1024)->required()->sensitive()->virtual()->label('Current password'),
            'password' => Property::string(1024)->required()->sensitive()->virtual()->label('New password'),
        ];
    }
}
