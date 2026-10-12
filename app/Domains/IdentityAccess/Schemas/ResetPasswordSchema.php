<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Set a new password with a reset token. */
final class ResetPasswordSchema extends TableSchema
{
    public static function table(): string
    {
        return 'user_accounts';
    }

    protected static function define(): array
    {
        return [
            'email' => Property::email()->required()->virtual()->label('Email'),
            'token' => Property::string(200)->required()->sensitive()->virtual()->label('Reset token'),
            'password' => Property::string(1024)->required()->sensitive()->virtual()->label('New password'),
        ];
    }
}
