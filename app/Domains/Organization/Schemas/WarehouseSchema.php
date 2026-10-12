<?php

declare(strict_types=1);

namespace App\Domains\Organization\Schemas;

use App\Domains\IdentityAccess\Models\Person;
use App\Domains\Organization\Enums\BranchStatus;
use App\Domains\Organization\Enums\WarehouseCapability;
use App\Domains\Organization\Enums\WarehouseKind;
use App\Domains\Organization\Enums\WarehouseStatus;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\StorageLocation;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

final class WarehouseSchema extends TableSchema
{
    public static function table(): string
    {
        return 'warehouses';
    }

    public static function stateEnum(): ?string
    {
        return WarehouseStatus::class;
    }

    protected static function define(): array
    {
        $location = static fn (string $label) => Property::uuid()->nullable()->references(StorageLocation::class)->label($label);

        return [
            'branch_id' => Property::uuid()->requiredOnCreate()->references(Branch::class, 'status', [BranchStatus::ACTIVE->value])->immutableAfter('active', 'inactive', 'closing', 'closed')->filterable()->label('Branch'),
            'code' => Property::string(32)->requiredOnCreate()->trim()->pattern('/^[A-Z0-9][A-Z0-9_-]{0,31}$/')->unique()->immutableAfter('active', 'inactive', 'closing', 'closed')->searchable()->sortable()->label('Code'),
            'name' => Property::string(160)->required()->trim()->searchable()->sortable()->label('Name'),
            'kind' => Property::enum(WarehouseKind::class)->default(WarehouseKind::MAIN->value)->filterable()->label('Warehouse kind'),
            'capabilities' => Property::arrayOf(Property::enum(WarehouseCapability::class))->nullable()->label('Capabilities', 'Defaults to the branch kind\'s capabilities when omitted.'),
            'address' => Property::json()->nullable()->label('Address'),
            'contact' => Property::json()->nullable()->label('Contact'),
            'manager_person_id' => Property::uuid()->nullable()->references(Person::class)->label('Manager'),
            'default_receiving_location_id' => $location('Default receiving location'),
            'default_dispatch_location_id' => $location('Default dispatch location'),
            'default_quarantine_location_id' => $location('Default quarantine location'),
            'default_damaged_location_id' => $location('Default damaged location'),
            'default_scrap_location_id' => $location('Default scrap location'),
            'default_returns_location_id' => $location('Default returns location'),
            'default_packing_location_id' => $location('Default packing location'),
            'default_storage_location_id' => $location('Default storage location'),
            'status' => Property::enum(WarehouseStatus::class)->serverControlled()->filterable()->label('Status'),
            'operational_calendar' => Property::json()->nullable()->label('Operational calendar'),
            'metadata' => Property::json()->nullable()->notReportable(),
        ];
    }
}
