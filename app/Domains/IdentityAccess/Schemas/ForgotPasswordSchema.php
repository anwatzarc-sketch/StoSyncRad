<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Request a password reset link. */
final class ForgotPasswordSchema extends TableSchema
{
    public static function table(): string
    {
        return 'user_accounts';
    }

    protected static function define(): array
    {
        return [
            'identifier' => Property::string(190)->required()->trim()->virtual()->label('Username or email'),
        ];
    }
}
