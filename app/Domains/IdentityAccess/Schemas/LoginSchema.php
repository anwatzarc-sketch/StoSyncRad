<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Sign-in with username or email and password. */
final class LoginSchema extends TableSchema
{
    public static function table(): string
    {
        return 'user_accounts';
    }

    protected static function define(): array
    {
        return [
            'identifier' => Property::string(190)->nullable()->trim()->virtual()->label('Username or email'),
            'username' => Property::string(190)->nullable()->trim()->virtual()->label('Username'),
            'password' => Property::string(1024)->required()->sensitive()->virtual()->label('Password'),
        ];
    }
}
