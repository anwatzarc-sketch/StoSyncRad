<?php

declare(strict_types=1);

namespace App\Domains\CustomerPortal\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Accepting an invitation: the invited contact sets their password. */
final class PortalInvitationAcceptSchema extends TableSchema
{
    public static function table(): string
    {
        return 'customer_invitations';
    }

    protected static function define(): array
    {
        return [
            'invitation' => Property::uuid()->required()->virtual()->label('Invitation'),
            'token' => Property::string(128)->required()->sensitive()->virtual()->label('Token'),
            'full_name' => Property::string(200)->nullable()->trim()->virtual()->label('Your name'),
            'phone' => Property::phone()->nullable()->trim()->virtual()->label('Phone'),
            'password' => Property::string(1024)->required()->sensitive()->virtual()->label('Password'),
            'password_confirmation' => Property::string(1024)->required()->sensitive()->virtual()->label('Repeat password'),
        ];
    }
}
