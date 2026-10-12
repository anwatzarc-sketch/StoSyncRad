<?php

declare(strict_types=1);

namespace App\Domains\Receiving\Schemas;

use App\Domains\IdentityAccess\Models\UserAccount;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Assign a put-away task. */
final class PutawayAssignSchema extends TableSchema
{
    public static function table(): string
    {
        return 'putaway_tasks';
    }

    protected static function define(): array
    {
        return [
            'assigned_to' => Property::uuid()->required()->references(UserAccount::class)->virtual()->label('Assignee'),
        ];
    }
}
