<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Second factor: authenticator code or recovery code. */
final class MfaVerifySchema extends TableSchema
{
    public static function table(): string
    {
        return 'user_accounts';
    }

    protected static function define(): array
    {
        return [
            'code' => Property::string(32)->required()->trim()->pattern('/^[A-Za-z0-9-]{6,32}$/')->sensitive()->virtual()->label('Code'),
            'remember_device' => Property::boolean()->default(false)->virtual()->label('Trust this device'),
        ];
    }
}
