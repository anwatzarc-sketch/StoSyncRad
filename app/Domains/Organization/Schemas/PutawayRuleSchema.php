<?php

declare(strict_types=1);

namespace App\Domains\Organization\Schemas;

use App\Domains\Organization\Enums\PutawayAppliesTo;
use App\Domains\Organization\Enums\StorageLocationUsage;
use App\Domains\Organization\Models\StorageLocation;
use App\Domains\Organization\Models\Warehouse;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

final class PutawayRuleSchema extends TableSchema
{
    public static function table(): string
    {
        return 'putaway_rules';
    }

    protected static function define(): array
    {
        return [
            'warehouse_id' => Property::uuid()->requiredOnCreate()->references(Warehouse::class)->label('Warehouse'),
            'applies_to' => Property::enum(PutawayAppliesTo::class)->required()->label('Applies to'),
            'target_id' => Property::uuid()->required()->label('Item, category or storage category'),
            'source_usage' => Property::enum(StorageLocationUsage::class)->default(StorageLocationUsage::RECEIVING->value)->label('From location usage'),
            'destination_location_id' => Property::uuid()->required()->references(StorageLocation::class)->label('Destination location'),
            'priority' => Property::integer()->default(10)->min(0)->max(1000)->label('Priority'),
            'is_active' => Property::boolean()->default(true)->label('Active'),
        ];
    }
}
