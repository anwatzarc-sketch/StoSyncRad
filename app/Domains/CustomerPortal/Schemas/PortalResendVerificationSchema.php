<?php

declare(strict_types=1);

namespace App\Domains\CustomerPortal\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Ask for a new confirmation link for a customer portal registration. */
final class PortalResendVerificationSchema extends TableSchema
{
    public static function table(): string
    {
        return 'customer_registrations';
    }

    protected static function define(): array
    {
        return [
            'email' => Property::email()->required()->trim()->virtual()->label('Email'),
        ];
    }
}
