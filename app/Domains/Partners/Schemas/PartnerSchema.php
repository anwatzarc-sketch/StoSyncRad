<?php

declare(strict_types=1);

namespace App\Domains\Partners\Schemas;

use App\Domains\Organization\Models\Branch;
use App\Domains\Partners\Enums\PartnerStatus;
use App\Domains\Partners\Enums\PartnerType;
use App\Domains\Platform\Models\LookupValue;
use App\Support\Enums\DataClassification;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

final class PartnerSchema extends TableSchema
{
    public static function table(): string
    {
        return 'partners';
    }

    protected static function define(): array
    {
        return [
            'code' => Property::string(40)->requiredOnCreate()->trim()->pattern('/^[A-Z0-9][A-Z0-9_-]{0,39}$/')->unique()->searchable()->sortable()->label('Code'),
            'name' => Property::string(200)->required()->trim()->searchable()->sortable()->label('Name'),
            'legal_name' => Property::string(200)->nullable()->trim()->label('Legal name'),
            'partner_types' => Property::arrayOf(Property::enum(PartnerType::class))->requiredOnCreate()->min(1)->max(5)->filterable()->label('Partner types'),
            'tax_id' => Property::string(80)->nullable()->trim()->label('Tax ID'),
            'addresses' => Property::json()->nullable()->label('Addresses'),
            'email' => Property::email()->nullable()->classification(DataClassification::PERSONAL_DATA->value)->label('Email'),
            'phone' => Property::phone()->nullable()->classification(DataClassification::PERSONAL_DATA->value)->label('Phone'),
            'website' => Property::url()->nullable()->label('Website'),
            'default_currency' => Property::string(3)->nullable()->pattern('/^[A-Z]{3}$/')->label('Default currency'),
            'payment_terms_lookup_value_id' => Property::uuid()->nullable()->references(LookupValue::class)->label('Payment terms'),
            'incoterm_lookup_value_id' => Property::uuid()->nullable()->references(LookupValue::class)->label('Incoterm'),
            'lead_time_days' => Property::integer()->nullable()->min(0)->max(3650)->label('Lead time (days)'),
            'rating' => Property::integer()->nullable()->min(1)->max(5)->label('Rating'),
            'is_internal' => Property::boolean()->default(false)->label('Internal recipient'),
            'internal_branch_id' => Property::uuid()->nullable()->references(Branch::class)->label('Internal branch'),
            'department_lookup_value_id' => Property::uuid()->nullable()->references(LookupValue::class)->label('Department'),
            'tracking_url_template' => Property::string(2048)->nullable()->label('Tracking URL template', 'Carrier tracking URL with {tracking_number} placeholder.'),
            'status' => Property::enum(PartnerStatus::class)->serverControlled()->filterable()->label('Status'),
            'notes' => Property::text(4000)->nullable()->label('Notes'),
        ];
    }
}
