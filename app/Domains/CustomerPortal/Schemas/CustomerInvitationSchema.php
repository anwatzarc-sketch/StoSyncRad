<?php

declare(strict_types=1);

namespace App\Domains\CustomerPortal\Schemas;

use App\Domains\CustomerPortal\Enums\InvitationStatus;
use App\Domains\Partners\Models\Partner;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Invite a contact of an existing customer to the customer portal. */
final class CustomerInvitationSchema extends TableSchema
{
    public static function table(): string
    {
        return 'customer_invitations';
    }

    protected static function define(): array
    {
        return [
            'partner_id' => Property::uuid()->required()->references(Partner::class)->label('Customer'),
            'email' => Property::email()->required()->trim()->label('Email'),
            'full_name' => Property::string(200)->required()->trim()->label('Name'),
            'as_admin' => Property::boolean()->nullable()->label('Customer administrator', 'Administrators can invite and remove colleagues of their company in the portal.'),
            'status' => Property::enum(InvitationStatus::class)->serverControlled()->label('Status'),
            'expires_at' => Property::datetime()->serverControlled()->label('Expires'),
            'accepted_at' => Property::datetime()->nullable()->serverControlled()->label('Accepted'),
        ];
    }
}
