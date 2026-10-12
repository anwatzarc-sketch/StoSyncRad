<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Re-authentication before a sensitive action (password or authenticator code). */
final class StepUpSchema extends TableSchema
{
    public static function table(): string
    {
        return 'user_accounts';
    }

    protected static function define(): array
    {
        return [
            'password' => Property::string(1024)->nullable()->sensitive()->virtual()->label('Password'),
            'code' => Property::string(32)->nullable()->trim()->pattern('/^[A-Za-z0-9-]{6,32}$/')->sensitive()->virtual()->label('Code'),
        ];
    }
}
