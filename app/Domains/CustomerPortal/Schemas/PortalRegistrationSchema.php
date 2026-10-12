<?php

declare(strict_types=1);

namespace App\Domains\CustomerPortal\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Customer self-registration. Business details are unverified until staff approve the registration. */
final class PortalRegistrationSchema extends TableSchema
{
    public static function table(): string
    {
        return 'customer_registrations';
    }

    protected static function define(): array
    {
        return [
            'full_name' => Property::string(200)->required()->trim()->virtual()->label('Your name'),
            'email' => Property::email()->required()->trim()->virtual()->label('Email'),
            'phone' => Property::phone()->nullable()->trim()->virtual()->label('Phone'),
            'company_name' => Property::string(200)->required()->trim()->virtual()->label('Company name'),
            'tax_id' => Property::string(80)->nullable()->trim()->virtual()->label('Tax ID (TIN)'),
            'address' => Property::json()->nullable()->virtual()->label('Address'),
            'message' => Property::text(2000)->nullable()->trim()->virtual()->label('Message'),
            'password' => Property::string(1024)->required()->sensitive()->virtual()->label('Password'),
            'password_confirmation' => Property::string(1024)->required()->sensitive()->virtual()->label('Repeat password'),
            'accept_terms' => Property::boolean()->required()->virtual()->label('I accept the terms'),
        ];
    }
}
