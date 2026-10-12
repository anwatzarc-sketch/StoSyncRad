<?php

declare(strict_types=1);

namespace App\Domains\Organization\Schemas;

use App\Domains\Platform\Models\LookupValue;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

final class CompanyProfileSchema extends TableSchema
{
    public static function table(): string
    {
        return 'company_profile';
    }

    protected static function define(): array
    {
        return [
            'legal_name' => Property::string(200)->required()->trim()->label('Legal name'),
            'trading_name' => Property::string(200)->nullable()->trim()->label('Trading name'),
            'registration_number' => Property::string(80)->nullable()->trim()->label('Registration number'),
            'tax_id' => Property::string(80)->nullable()->trim()->label('Tax ID'),
            'registered_address' => Property::json()->nullable()->label('Registered address'),
            'operational_address' => Property::json()->nullable()->label('Operational address'),
            'phone' => Property::phone()->nullable()->label('Phone'),
            'email' => Property::email()->nullable()->label('Email'),
            'website' => Property::url()->nullable()->label('Website'),
            'fiscal_year_start_month' => Property::integer()->min(1)->max(12)->default(1)->label('Fiscal year start month'),
            'base_currency' => Property::string(3)->required()->pattern('/^[A-Z]{3}$/')->label('Base currency'),
            'industry_lookup_value_id' => Property::uuid()->nullable()->references(LookupValue::class)->label('Industry'),
            'notes' => Property::text(4000)->nullable()->label('Notes'),
        ];
    }
}
