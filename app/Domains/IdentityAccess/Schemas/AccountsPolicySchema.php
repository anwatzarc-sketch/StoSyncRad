<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Schemas;

use App\Domains\IdentityAccess\Enums\AccountsPerPersonPolicy;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Accounts-per-person corporate policy. */
final class AccountsPolicySchema extends TableSchema
{
    public static function table(): string
    {
        return 'user_accounts';
    }

    protected static function define(): array
    {
        return [
            'policy' => Property::enum(AccountsPerPersonPolicy::class)->required()->virtual()->label('Policy'),
            'reason' => Property::text(2000)->required()->trim()->virtual()->label('Reason'),
        ];
    }
}
