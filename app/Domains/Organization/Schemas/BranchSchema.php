<?php

declare(strict_types=1);

namespace App\Domains\Organization\Schemas;

use App\Domains\IdentityAccess\Models\Person;
use App\Domains\Organization\Enums\BranchKind;
use App\Domains\Organization\Enums\BranchStatus;
use App\Domains\Organization\Models\Branch;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

final class BranchSchema extends TableSchema
{
    public static function table(): string
    {
        return 'branches';
    }

    public static function stateEnum(): ?string
    {
        return BranchStatus::class;
    }

    protected static function define(): array
    {
        return [
            'code' => Property::string(32)->requiredOnCreate()->trim()->pattern('/^[A-Z0-9][A-Z0-9_-]{0,31}$/')->unique()->immutableAfter('active', 'inactive', 'closed')->searchable()->sortable()->label('Code', 'Immutable short code, e.g. HQ, DEPOT-SEA.'),
            'name' => Property::string(160)->required()->trim()->searchable()->sortable()->label('Name'),
            'kind' => Property::enum(BranchKind::class)->required()->filterable()->label('Branch kind'),
            'parent_branch_id' => Property::uuid()->nullable()->references(Branch::class)->label('Parent branch'),
            'address' => Property::json()->nullable()->label('Address'),
            'contact' => Property::json()->nullable()->label('Contact'),
            'timezone' => Property::string(64)->nullable()->pattern('/^[A-Za-z_]+(\/[A-Za-z0-9_+\-]+)*$/')->label('Timezone override'),
            'manager_person_id' => Property::uuid()->nullable()->references(Person::class)->label('Manager'),
            'status' => Property::enum(BranchStatus::class)->serverControlled()->filterable()->label('Status'),
            'opened_on' => Property::date()->nullable()->label('Opened on'),
            'closed_on' => Property::date()->nullable()->serverControlled(),
            'metadata' => Property::json()->nullable()->notReportable(),
        ];
    }
}
