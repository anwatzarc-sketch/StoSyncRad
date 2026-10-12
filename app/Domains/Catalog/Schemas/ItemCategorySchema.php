<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Schemas;

use App\Domains\Catalog\Enums\AbcClass;
use App\Domains\Catalog\Enums\CategoryStatus;
use App\Domains\Catalog\Enums\CostingMethod;
use App\Domains\Catalog\Enums\ExpiryPolicy;
use App\Domains\Catalog\Enums\QcPolicy;
use App\Domains\Catalog\Enums\RemovalStrategy;
use App\Domains\Catalog\Enums\TrackingMode;
use App\Domains\Catalog\Models\ItemCategory;
use App\Domains\Catalog\Models\UnitOfMeasure;
use App\Domains\Organization\Models\StorageCategory;
use App\Support\Enums\DataClassification;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/**
 * Hierarchical category with inheritable policy defaults (Chapter 06 §6.7).
 * A null policy means "inherit from the nearest ancestor".
 */
final class ItemCategorySchema extends TableSchema
{
    public static function table(): string
    {
        return 'item_categories';
    }

    protected static function define(): array
    {
        return [
            'parent_id' => Property::uuid()->nullable()->references(ItemCategory::class)->filterable()->label('Parent category'),
            'code' => Property::string(60)->requiredOnCreate()->trim()->pattern('/^[A-Z0-9][A-Z0-9_.-]{0,59}$/')->unique()->searchable()->sortable()->label('Code'),
            'name' => Property::string(160)->required()->trim()->searchable()->sortable()->label('Name'),
            'full_path' => Property::string(1000)->serverControlled()->searchable()->sortable()->label('Path'),
            'code_path' => Property::string(600)->serverControlled()->label('Code path'),
            'depth' => Property::integer()->serverControlled()->filterable()->label('Depth'),
            'sort_order' => Property::integer()->default(0)->min(0)->label('Sort order'),
            'description' => Property::text(4000)->nullable()->label('Description'),
            'status' => Property::enum(CategoryStatus::class)->serverControlled()->filterable()->label('Status'),
            'default_tracking_mode' => Property::enum(TrackingMode::class)->nullable()->label('Default tracking'),
            'default_expiry_policy' => Property::enum(ExpiryPolicy::class)->nullable()->label('Default expiry policy'),
            'default_removal_strategy' => Property::enum(RemovalStrategy::class)->nullable()->label('Default removal strategy'),
            'default_qc_policy' => Property::enum(QcPolicy::class)->nullable()->label('Default QC policy'),
            'default_costing_method' => Property::enum(CostingMethod::class)->nullable()->writableBy('catalog.item.manage_cost')->label('Default costing method'),
            'default_uom_id' => Property::uuid()->nullable()->references(UnitOfMeasure::class)->label('Default unit'),
            'default_counting_frequency_days' => Property::integer()->nullable()->min(1)->max(3650)->label('Default counting frequency (days)'),
            'default_storage_category_id' => Property::uuid()->nullable()->references(StorageCategory::class)->label('Default storage category'),
            'default_abc_class' => Property::enum(AbcClass::class)->nullable()->label('Default ABC class'),
            'requires_approval_for_issue' => Property::boolean()->nullable()->label('Issue requires approval'),
            'is_hazardous' => Property::boolean()->nullable()->label('Hazardous'),
            'cost_visibility_classification' => Property::enum(DataClassification::class)->nullable()->writableBy('catalog.item.manage_cost')->label('Cost visibility'),
            'translations' => Property::json()->nullable()->virtual()->label('Localized names', '{"am": "…", "om": "…"}'),
            'metadata' => Property::json()->nullable()->notReportable(),
        ];
    }
}
