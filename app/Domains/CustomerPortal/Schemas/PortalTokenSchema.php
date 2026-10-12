<?php

declare(strict_types=1);

namespace App\Domains\CustomerPortal\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** The registration id and one-time token from a customer portal e-mail link. */
final class PortalTokenSchema extends TableSchema
{
    public static function table(): string
    {
        return 'customer_registrations';
    }

    protected static function define(): array
    {
        return [
            'registration' => Property::uuid()->required()->virtual()->label('Registration'),
            'token' => Property::string(128)->required()->sensitive()->virtual()->label('Token'),
        ];
    }
}
