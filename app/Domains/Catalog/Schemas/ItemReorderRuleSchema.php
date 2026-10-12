<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Schemas;

use App\Domains\Catalog\Enums\ReplenishmentMethod;
use App\Domains\Catalog\Models\Item;
use App\Domains\Catalog\Models\ItemVariant;
use App\Domains\Organization\Models\Warehouse;
use App\Domains\Partners\Models\Partner;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/**
 * Per-item, optionally per-warehouse replenishment rule (Chapter 06 §6.6.5).
 * A warehouse rule overrides the company rule (warehouse_id null).
 */
final class ItemReorderRuleSchema extends TableSchema
{
    public static function table(): string
    {
        return 'item_reorder_rules';
    }

    protected static function define(): array
    {
        $qty = static fn (string $label) => Property::quantity()->nullable()->nonNegative()->label($label);

        return [
            'item_id' => Property::uuid()->requiredOnCreate()->references(Item::class)->filterable()->label('Item'),
            'item_variant_id' => Property::uuid()->nullable()->references(ItemVariant::class)->label('Variant'),
            'warehouse_id' => Property::uuid()->nullable()->references(Warehouse::class)->filterable()->label('Warehouse', 'Blank = company default rule.'),
            'min_qty' => Property::quantity()->default('0')->nonNegative()->label('Minimum'),
            'max_qty' => $qty('Maximum'),
            'reorder_point' => Property::quantity()->default('0')->nonNegative()->label('Reorder point'),
            'reorder_qty' => $qty('Reorder quantity'),
            'safety_stock' => Property::quantity()->default('0')->nonNegative()->label('Safety stock'),
            'multiple_of_qty' => $qty('Order multiple'),
            'preferred_supplier_id' => Property::uuid()->nullable()->references(Partner::class)->label('Preferred supplier'),
            'preferred_source_warehouse_id' => Property::uuid()->nullable()->references(Warehouse::class)->label('Preferred source warehouse'),
            'replenishment_method' => Property::enum(ReplenishmentMethod::class)->default(ReplenishmentMethod::PURCHASE->value)->filterable()->label('Replenish by'),
            'lead_time_days' => Property::integer()->nullable()->min(0)->max(3650)->label('Lead time (days)'),
            'review_period_days' => Property::integer()->nullable()->min(1)->max(365)->label('Review period (days)'),
            'is_active' => Property::boolean()->default(true)->filterable()->label('Active'),
        ];
    }
}
