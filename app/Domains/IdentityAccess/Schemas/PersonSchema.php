<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Schemas;

use App\Domains\IdentityAccess\Enums\PersonStatus;
use App\Domains\Organization\Models\Branch;
use App\Domains\Platform\Models\LookupValue;
use App\Support\Enums\DataClassification;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

final class PersonSchema extends TableSchema
{
    public static function table(): string
    {
        return 'persons';
    }

    protected static function define(): array
    {
        return [
            'full_name' => Property::string(200)->required()->trim()->searchable()->sortable()->label('Full name'),
            'employee_number' => Property::string(40)->nullable()->trim()->unique()->searchable()->label('Employee number'),
            'national_id' => Property::string(64)->nullable()->trim()->sensitive()->classification(DataClassification::PERSONAL_DATA->value)->label('National ID'),
            'email' => Property::email()->nullable()->trim()->searchable()->classification(DataClassification::PERSONAL_DATA->value)->label('Email'),
            'phone' => Property::phone()->nullable()->trim()->classification(DataClassification::PERSONAL_DATA->value)->label('Phone'),
            'branch_id' => Property::uuid()->nullable()->references(Branch::class)->filterable()->label('Home branch'),
            'department_lookup_value_id' => Property::uuid()->nullable()->references(LookupValue::class, 'is_active', ['1', 'true', true])->label('Department'),
            'job_title' => Property::string(120)->nullable()->trim()->label('Job title'),
            'status' => Property::enum(PersonStatus::class)->default(PersonStatus::ACTIVE->value)->filterable()->label('Status'),
            'archived_at' => Property::datetime()->nullable()->serverControlled(),
        ];
    }
}
