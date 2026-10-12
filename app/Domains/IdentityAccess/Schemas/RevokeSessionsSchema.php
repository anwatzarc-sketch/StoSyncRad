<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Sign an account out everywhere. */
final class RevokeSessionsSchema extends TableSchema
{
    public static function table(): string
    {
        return 'user_accounts';
    }

    protected static function define(): array
    {
        return [
            'include_tokens' => Property::boolean()->default(true)->virtual()->label('Also revoke API tokens'),
            'reason' => Property::text(2000)->nullable()->trim()->virtual()->label('Reason'),
        ];
    }
}
