<?php

declare(strict_types=1);

namespace App\Domains\Planning\Schemas;

use App\Domains\Catalog\Models\Item;
use App\Domains\Catalog\Models\ItemVariant;
use App\Domains\Organization\Models\Warehouse;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

final class AllocationPlanLineSchema extends TableSchema
{
    public static function table(): string
    {
        return 'allocation_plan_lines';
    }

    protected static function define(): array
    {
        return [
            'destination_warehouse_id' => Property::uuid()->required()->references(Warehouse::class, 'status', ['active'])->label('Destination'),
            'item_id' => Property::uuid()->required()->references(Item::class, 'status', ['active'])->label('Item'),
            'item_variant_id' => Property::uuid()->nullable()->references(ItemVariant::class)->label('Variant'),
            'quantity' => Property::quantity()->default('0')->nonNegative()->label('Allocated quantity (base unit)'),
            'cap_quantity' => Property::quantity()->nullable()->nonNegative()->label('Cap', 'Maximum this destination may receive.'),
            'priority' => Property::integer()->default(100)->min(1)->max(10000)->label('Priority', 'Lower is served first (priority_order policy).'),
            'notes' => Property::text(1000)->nullable()->label('Notes'),
        ];
    }
}
