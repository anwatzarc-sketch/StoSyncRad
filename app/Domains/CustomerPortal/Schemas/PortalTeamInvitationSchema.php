<?php

declare(strict_types=1);

namespace App\Domains\CustomerPortal\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** A customer administrator invites a colleague of the same company. */
final class PortalTeamInvitationSchema extends TableSchema
{
    public static function table(): string
    {
        return 'customer_invitations';
    }

    protected static function define(): array
    {
        return [
            'email' => Property::email()->required()->trim()->label('Email'),
            'full_name' => Property::string(200)->required()->trim()->label('Name'),
            'as_admin' => Property::boolean()->nullable()->label('Administrator', 'Can invite and remove colleagues.'),
        ];
    }
}
