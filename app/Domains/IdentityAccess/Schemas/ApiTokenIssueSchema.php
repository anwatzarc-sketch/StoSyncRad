<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Schemas;

use App\Domains\IdentityAccess\Models\UserAccount;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Issue an API token to a service account. */
final class ApiTokenIssueSchema extends TableSchema
{
    public static function table(): string
    {
        return 'personal_access_tokens';
    }

    protected static function define(): array
    {
        return [
            'user_account_id' => Property::uuid()->required()->references(UserAccount::class)->virtual()->label('Service account'),
            'name' => Property::string(120)->required()->trim()->virtual()->label('Token name'),
            'expires_in_days' => Property::integer()->nullable()->min(1)->max(3650)->virtual()->label('Expires in (days)'),
        ];
    }
}
