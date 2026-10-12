<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Confirm authenticator enrolment. */
final class MfaCodeSchema extends TableSchema
{
    public static function table(): string
    {
        return 'user_accounts';
    }

    protected static function define(): array
    {
        return [
            'code' => Property::string(8)->required()->trim()->pattern('/^\d{6,8}$/')->sensitive()->virtual()->label('Code'),
        ];
    }
}
