<?php

declare(strict_types=1);

namespace App\Domains\Organization\Schemas;

use App\Domains\Organization\Enums\StorageLocationLevel;
use App\Domains\Organization\Enums\StorageLocationStatus;
use App\Domains\Organization\Enums\StorageLocationUsage;
use App\Domains\Organization\Models\StorageCategory;
use App\Domains\Organization\Models\StorageLocation;
use App\Domains\Organization\Models\Warehouse;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

final class StorageLocationSchema extends TableSchema
{
    public static function table(): string
    {
        return 'storage_locations';
    }

    protected static function define(): array
    {
        return [
            'warehouse_id' => Property::uuid()->requiredOnCreate()->references(Warehouse::class)->filterable()->label('Warehouse'),
            'parent_id' => Property::uuid()->nullable()->references(StorageLocation::class)->label('Parent location'),
            'code' => Property::string(40)->requiredOnCreate()->trim()->pattern('/^[A-Z0-9][A-Z0-9_.-]{0,39}$/')->searchable()->sortable()->label('Code'),
            'name' => Property::string(160)->required()->trim()->searchable()->label('Name'),
            'full_path' => Property::string(400)->serverControlled()->searchable()->sortable()->label('Path'),
            'level' => Property::enum(StorageLocationLevel::class)->default(StorageLocationLevel::BIN->value)->filterable()->label('Level'),
            'usage' => Property::enum(StorageLocationUsage::class)->default(StorageLocationUsage::STORAGE->value)->filterable()->label('Usage'),
            'storage_category_id' => Property::uuid()->nullable()->references(StorageCategory::class)->label('Storage category'),
            'capacity' => Property::json()->nullable()->label('Capacity'),
            'temperature_range' => Property::json()->nullable()->label('Temperature range'),
            'is_pickable' => Property::boolean()->default(true)->label('Pickable'),
            'is_putaway_target' => Property::boolean()->default(true)->label('Put-away target'),
            'is_system' => Property::boolean()->serverControlled(),
            'counting_frequency_days' => Property::integer()->nullable()->min(1)->max(3650)->label('Counting frequency (days)'),
            'last_counted_at' => Property::datetime()->nullable()->serverControlled(),
            'barcode' => Property::barcode()->nullable()->unique()->searchable()->label('Barcode'),
            'status' => Property::enum(StorageLocationStatus::class)->serverControlled()->filterable()->label('Status'),
            'sort_order' => Property::integer()->default(0)->min(0)->label('Sort order'),
        ];
    }
}
