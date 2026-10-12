<?php

use App\Domains\Catalog\Enums\AbcClass;
use App\Domains\Catalog\Enums\AttributeDisplayType;
use App\Domains\Catalog\Enums\BarcodeSymbology;
use App\Domains\Catalog\Enums\CategoryStatus;
use App\Domains\Catalog\Enums\CostingMethod;
use App\Domains\Catalog\Enums\ExpiryPolicy;
use App\Domains\Catalog\Enums\ItemStatus;
use App\Domains\Catalog\Enums\ItemType;
use App\Domains\Catalog\Enums\LotNumberPolicy;
use App\Domains\Catalog\Enums\OutOfStockBehavior;
use App\Domains\Catalog\Enums\QcPolicy;
use App\Domains\Catalog\Enums\ReceiptControlPolicy;
use App\Domains\Catalog\Enums\RemovalStrategy;
use App\Domains\Catalog\Enums\ReplenishmentMethod;
use App\Domains\Catalog\Enums\TrackingMode;
use App\Domains\Catalog\Enums\UomCategoryKind;
use App\Domains\Catalog\Enums\UomStatus;
use App\Domains\Catalog\Enums\VariantCreationMode;
use App\Domains\Catalog\Enums\WarningMode;
use App\Support\Database\Pg;
use App\Support\Enums\DataClassification;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uom_categories', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->string('code', 40)->unique();
            $t->string('name', 120);
            Pg::enumColumn($t, 'kind');
            Pg::timestamps($t);
        });
        Pg::enumCheck('uom_categories', 'kind', UomCategoryKind::class);

        Schema::create('units_of_measure', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->uuid('uom_category_id');
            $t->string('code', 20)->unique();
            $t->string('name', 80);
            $t->string('symbol', 20);
            $t->decimal('ratio_to_reference', 20, 10)->default(1);
            $t->unsignedSmallInteger('rounding_precision')->default(6);
            $t->boolean('is_reference')->default(false);
            $t->boolean('is_whole_number')->default(false);
            Pg::enumColumn($t, 'status', default: UomStatus::ACTIVE->value);
            Pg::timestamps($t);
            $t->foreign('uom_category_id')->references('id')->on('uom_categories')->restrictOnDelete();
        });
        Pg::enumCheck('units_of_measure', 'status', UomStatus::class);
        Pg::check('units_of_measure', 'chk_uom_ratio_positive', 'ratio_to_reference > 0');
        Pg::check('units_of_measure', 'chk_uom_precision', 'rounding_precision <= 6');
        DB::statement('CREATE UNIQUE INDEX units_of_measure_one_reference_uq ON units_of_measure (uom_category_id) WHERE is_reference = true');

        Schema::create('item_categories', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->uuid('parent_id')->nullable();
            $t->string('code', 60)->unique();
            $t->string('name', 160);
            $t->string('full_path', 1000)->unique();
            $t->string('code_path', 600);
            $t->unsignedSmallInteger('depth')->default(0);
            $t->unsignedInteger('sort_order')->default(0);
            $t->text('description')->nullable();
            Pg::enumColumn($t, 'status', default: CategoryStatus::ACTIVE->value);
            Pg::enumColumn($t, 'default_tracking_mode', nullable: true);
            Pg::enumColumn($t, 'default_expiry_policy', nullable: true);
            Pg::enumColumn($t, 'default_removal_strategy', nullable: true);
            Pg::enumColumn($t, 'default_qc_policy', nullable: true);
            Pg::enumColumn($t, 'default_costing_method', nullable: true);
            $t->uuid('default_uom_id')->nullable();
            $t->unsignedInteger('default_counting_frequency_days')->nullable();
            $t->uuid('default_storage_category_id')->nullable();
            Pg::enumColumn($t, 'default_abc_class', nullable: true);
            $t->boolean('requires_approval_for_issue')->nullable();
            $t->boolean('is_hazardous')->nullable();
            Pg::enumColumn($t, 'cost_visibility_classification', nullable: true);
            $t->jsonb('metadata')->nullable();
            Pg::actorColumns($t);
            Pg::timestamps($t);
            $t->foreign('parent_id')->references('id')->on('item_categories')->restrictOnDelete();
            $t->foreign('default_uom_id')->references('id')->on('units_of_measure')->restrictOnDelete();
            $t->foreign('default_storage_category_id')->references('id')->on('storage_categories')->restrictOnDelete();
            $t->index('parent_id');
        });
        Pg::enumCheck('item_categories', 'status', CategoryStatus::class);
        Pg::enumCheck('item_categories', 'default_tracking_mode', TrackingMode::class);
        Pg::enumCheck('item_categories', 'default_expiry_policy', ExpiryPolicy::class);
        Pg::enumCheck('item_categories', 'default_removal_strategy', RemovalStrategy::class);
        Pg::enumCheck('item_categories', 'default_qc_policy', QcPolicy::class);
        Pg::enumCheck('item_categories', 'default_costing_method', CostingMethod::class);
        Pg::enumCheck('item_categories', 'default_abc_class', AbcClass::class);
        Pg::enumCheck('item_categories', 'cost_visibility_classification', DataClassification::class);
        DB::statement('CREATE INDEX item_categories_path_trgm ON item_categories USING gin (full_path gin_trgm_ops)');

        Schema::create('item_category_translations', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->uuid('item_category_id');
            $t->string('locale', 12);
            $t->string('name', 160);
            Pg::timestamps($t);
            $t->foreign('item_category_id')->references('id')->on('item_categories')->restrictOnDelete();
            $t->unique(['item_category_id', 'locale']);
        });

        Schema::create('item_category_moves', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->string('subject_type', 20); // category | item
            $t->uuid('subject_id');
            $t->uuid('from_category_id')->nullable();
            $t->uuid('to_category_id')->nullable();
            $t->uuid('moved_by')->nullable();
            $t->text('reason')->nullable();
            $t->timestampTz('created_at');
            $t->index(['subject_type', 'subject_id']);
        });
        Pg::appendOnly('item_category_moves');

        Schema::create('tags', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->string('code', 60)->unique();
            $t->string('name', 120);
            $t->string('color', 9)->nullable();
            $t->boolean('is_active')->default(true);
            Pg::timestamps($t);
        });

        Schema::create('qc_checklist_templates', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->string('code', 60)->unique();
            $t->string('name', 160);
            $t->text('description')->nullable();
            $t->jsonb('checks');    // [{code,label,type(enum boolean|numeric|text|choice),required,min,max,choices}]
            $t->boolean('is_active')->default(true);
            Pg::actorColumns($t);
            Pg::timestamps($t);
        });

        Schema::create('items', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            // General
            $t->string('sku', 64)->unique();
            $t->string('name', 200);
            Pg::enumColumn($t, 'item_type', default: ItemType::STOCKABLE->value);
            $t->boolean('is_sellable')->default(true);
            $t->boolean('is_purchasable')->default(true);
            $t->boolean('is_transferable')->default(true);
            $t->uuid('category_id');
            $t->uuid('brand_lookup_value_id')->nullable();
            $t->uuid('manufacturer_partner_id')->nullable();
            $t->string('model_number', 100)->nullable();
            $t->string('description_short', 500)->nullable();
            $t->text('description_long')->nullable();
            $t->text('internal_notes')->nullable();
            Pg::enumColumn($t, 'status', default: ItemStatus::DRAFT->value);
            $t->uuid('responsible_person_id')->nullable();
            $t->boolean('is_critical')->default(false);
            $t->boolean('requires_approval_for_issue')->nullable();
            // Identification
            $t->string('hs_code', 20)->nullable();
            $t->string('country_of_origin', 2)->nullable();
            $t->string('customs_description', 255)->nullable();
            // Units & physical
            $t->uuid('base_uom_id');
            $t->uuid('purchase_uom_id')->nullable();
            $t->uuid('sales_uom_id')->nullable();
            $t->uuid('transfer_uom_id')->nullable();
            $t->decimal('weight', 12, 4)->nullable();
            $t->uuid('weight_uom_id')->nullable();
            $t->decimal('volume', 12, 6)->nullable();
            $t->uuid('volume_uom_id')->nullable();
            $t->decimal('length', 12, 4)->nullable();
            $t->decimal('width', 12, 4)->nullable();
            $t->decimal('height', 12, 4)->nullable();
            $t->uuid('dimension_uom_id')->nullable();
            // Inventory & logistics
            Pg::enumColumn($t, 'tracking_mode', default: TrackingMode::NONE->value);
            Pg::enumColumn($t, 'lot_number_policy', default: LotNumberPolicy::EITHER->value);
            Pg::enumColumn($t, 'expiry_policy', default: ExpiryPolicy::INHERIT_CATEGORY->value);
            $t->unsignedInteger('shelf_life_days')->nullable();
            $t->unsignedInteger('best_before_offset_days')->nullable();
            $t->unsignedInteger('removal_offset_days')->nullable();
            $t->unsignedInteger('alert_offset_days')->nullable();
            Pg::enumColumn($t, 'removal_strategy', default: RemovalStrategy::INHERIT_CATEGORY->value);
            Pg::enumColumn($t, 'quality_control_policy', default: QcPolicy::INHERIT_CATEGORY->value);
            $t->uuid('qc_checklist_template_id')->nullable();
            $t->uuid('storage_category_id')->nullable();
            $t->jsonb('preferred_storage_conditions')->nullable();
            $t->boolean('is_hazardous')->default(false);
            $t->boolean('is_fragile')->default(false);
            $t->boolean('is_perishable')->default(false);
            Pg::enumColumn($t, 'abc_class', default: AbcClass::UNCLASSIFIED->value);
            $t->boolean('abc_override')->default(false);
            $t->unsignedInteger('counting_frequency_days')->nullable();
            $t->unsignedInteger('supplier_lead_time_days')->nullable();
            $t->unsignedInteger('customer_lead_time_days')->nullable();
            $t->string('receipt_description', 500)->nullable();
            $t->string('delivery_description', 500)->nullable();
            $t->string('transfer_description', 500)->nullable();
            $t->boolean('allow_negative_stock_override')->default(false);
            $t->decimal('min_order_qty', 20, 6)->nullable();
            $t->decimal('max_order_qty', 20, 6)->nullable();
            $t->decimal('order_multiple', 20, 6)->nullable();
            // Purchasing
            $t->string('purchase_description', 500)->nullable();
            $t->uuid('purchase_warning_lookup_value_id')->nullable();
            Pg::enumColumn($t, 'purchase_warning_mode', default: WarningMode::NONE->value);
            Pg::enumColumn($t, 'receipt_control_policy', default: ReceiptControlPolicy::RECEIVED_QTY->value);
            $t->decimal('last_purchase_cost', 20, 8)->nullable();
            $t->timestampTz('last_purchased_at')->nullable();
            // Sales (stock-side)
            $t->decimal('list_price', 20, 4)->nullable();
            $t->string('list_price_currency', 3)->nullable();
            $t->string('sales_description', 500)->nullable();
            $t->uuid('sales_warning_lookup_value_id')->nullable();
            Pg::enumColumn($t, 'sales_warning_mode', default: WarningMode::NONE->value);
            Pg::enumColumn($t, 'out_of_stock_behavior', default: OutOfStockBehavior::BLOCK->value);
            $t->boolean('is_available_online')->default(false);
            // Costing & valuation
            Pg::enumColumn($t, 'costing_method', default: CostingMethod::INHERIT_CATEGORY->value);
            $t->decimal('standard_cost', 20, 8)->nullable();
            $t->string('standard_cost_currency', 3)->nullable();
            $t->date('standard_cost_valid_from')->nullable();
            $t->decimal('current_average_cost', 20, 8)->default(0);
            $t->string('valuation_account_ref', 64)->nullable();
            $t->string('expense_account_ref', 64)->nullable();
            $t->string('price_difference_account_ref', 64)->nullable();
            Pg::enumColumn($t, 'cost_visibility_classification', default: DataClassification::COST_SENSITIVE->value);
            // Variants / kits
            $t->boolean('has_variants')->default(false);
            // Lifecycle bookkeeping
            $t->timestampTz('first_posted_at')->nullable();
            $t->timestampTz('archived_at')->nullable();
            $t->unsignedInteger('version')->default(1);
            Pg::actorColumns($t);
            Pg::timestamps($t);

            $t->foreign('category_id')->references('id')->on('item_categories')->restrictOnDelete();
            $t->foreign('manufacturer_partner_id')->references('id')->on('partners')->restrictOnDelete();
            $t->foreign('responsible_person_id')->references('id')->on('persons')->restrictOnDelete();
            foreach (['base_uom_id', 'purchase_uom_id', 'sales_uom_id', 'transfer_uom_id', 'weight_uom_id', 'volume_uom_id', 'dimension_uom_id'] as $col) {
                $t->foreign($col)->references('id')->on('units_of_measure')->restrictOnDelete();
            }
            $t->foreign('qc_checklist_template_id')->references('id')->on('qc_checklist_templates')->restrictOnDelete();
            $t->foreign('storage_category_id')->references('id')->on('storage_categories')->restrictOnDelete();
            $t->index(['category_id', 'status']);
            $t->index('status');
        });
        foreach ([
            'item_type' => ItemType::class, 'status' => ItemStatus::class, 'tracking_mode' => TrackingMode::class,
            'lot_number_policy' => LotNumberPolicy::class, 'expiry_policy' => ExpiryPolicy::class, 'removal_strategy' => RemovalStrategy::class,
            'quality_control_policy' => QcPolicy::class, 'abc_class' => AbcClass::class, 'purchase_warning_mode' => WarningMode::class,
            'receipt_control_policy' => ReceiptControlPolicy::class, 'sales_warning_mode' => WarningMode::class,
            'out_of_stock_behavior' => OutOfStockBehavior::class, 'costing_method' => CostingMethod::class,
            'cost_visibility_classification' => DataClassification::class,
        ] as $col => $enum) {
            Pg::enumCheck('items', $col, $enum);
        }
        Pg::check('items', 'chk_items_order_qty', 'min_order_qty IS NULL OR max_order_qty IS NULL OR min_order_qty <= max_order_qty');
        Pg::check('items', 'chk_items_removal_le_shelf', 'removal_offset_days IS NULL OR shelf_life_days IS NULL OR removal_offset_days <= shelf_life_days');
        Pg::check('items', 'chk_items_standard_cost', "costing_method <> 'standard' OR standard_cost IS NOT NULL");
        DB::statement('CREATE INDEX items_name_trgm ON items USING gin (name gin_trgm_ops)');
        DB::statement('CREATE INDEX items_sku_trgm ON items USING gin (sku gin_trgm_ops)');

        Schema::create('item_tags', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->uuid('item_id');
            $t->uuid('tag_id');
            $t->foreign('item_id')->references('id')->on('items')->restrictOnDelete();
            $t->foreign('tag_id')->references('id')->on('tags')->restrictOnDelete();
            $t->unique(['item_id', 'tag_id']);
        });

        Schema::create('item_images', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->uuid('item_id');
            $t->uuid('item_variant_id')->nullable();
            $t->string('path', 512);
            $t->string('mime', 100);
            $t->unsignedInteger('sort_order')->default(0);
            $t->boolean('is_primary')->default(false);
            Pg::timestamps($t);
            $t->foreign('item_id')->references('id')->on('items')->restrictOnDelete();
        });

        Schema::create('item_attributes', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->string('code', 60)->unique();
            $t->string('name', 120);
            Pg::enumColumn($t, 'display_type', default: AttributeDisplayType::SELECT->value);
            Pg::enumColumn($t, 'create_variant', default: VariantCreationMode::ALWAYS->value);
            $t->unsignedInteger('sort_order')->default(0);
            Pg::timestamps($t);
        });
        Pg::enumCheck('item_attributes', 'display_type', AttributeDisplayType::class);
        Pg::enumCheck('item_attributes', 'create_variant', VariantCreationMode::class);

        Schema::create('item_attribute_values', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->uuid('item_attribute_id');
            $t->string('code', 60);
            $t->string('name', 120);
            $t->string('html_color', 9)->nullable();
            $t->unsignedInteger('sort_order')->default(0);
            Pg::timestamps($t);
            $t->foreign('item_attribute_id')->references('id')->on('item_attributes')->restrictOnDelete();
            $t->unique(['item_attribute_id', 'code']);
        });

        Schema::create('item_attribute_lines', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->uuid('item_id');
            $t->uuid('item_attribute_id');
            $t->jsonb('value_ids');
            Pg::timestamps($t);
            $t->foreign('item_id')->references('id')->on('items')->restrictOnDelete();
            $t->foreign('item_attribute_id')->references('id')->on('item_attributes')->restrictOnDelete();
            $t->unique(['item_id', 'item_attribute_id']);
        });

        Schema::create('item_variants', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->uuid('item_id');
            $t->string('combination_hash', 64);
            $t->jsonb('attribute_values');      // [{attribute_id, value_id}]
            $t->string('sku', 80)->unique();
            $t->string('name_suffix', 200);
            $t->decimal('weight', 12, 4)->nullable();
            $t->decimal('volume', 12, 6)->nullable();
            $t->decimal('extra_list_price', 20, 4)->nullable();
            Pg::enumColumn($t, 'status', default: ItemStatus::ACTIVE->value);
            $t->timestampTz('first_posted_at')->nullable();
            Pg::timestamps($t);
            $t->foreign('item_id')->references('id')->on('items')->restrictOnDelete();
            $t->unique(['item_id', 'combination_hash']);
        });
        Pg::enumCheck('item_variants', 'status', ItemStatus::class);

        Schema::create('item_packagings', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->uuid('item_id');
            $t->string('name', 120);
            $t->decimal('contained_qty', 20, 6);
            $t->uuid('packaging_type_lookup_value_id')->nullable();
            $t->decimal('weight', 12, 4)->nullable();
            $t->decimal('length', 12, 4)->nullable();
            $t->decimal('width', 12, 4)->nullable();
            $t->decimal('height', 12, 4)->nullable();
            $t->boolean('is_default_purchase')->default(false);
            $t->boolean('is_default_sales')->default(false);
            $t->boolean('is_default_transfer')->default(false);
            Pg::timestamps($t);
            $t->foreign('item_id')->references('id')->on('items')->restrictOnDelete();
        });
        Pg::check('item_packagings', 'chk_item_packagings_qty', 'contained_qty > 0');

        Schema::create('item_barcodes', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->uuid('item_id');
            $t->uuid('item_variant_id')->nullable();
            $t->uuid('item_packaging_id')->nullable();
            $t->string('barcode', 128)->unique();
            Pg::enumColumn($t, 'symbology', default: BarcodeSymbology::CODE128->value);
            $t->boolean('is_primary')->default(false);
            Pg::timestamps($t);
            $t->foreign('item_id')->references('id')->on('items')->restrictOnDelete();
            $t->foreign('item_variant_id')->references('id')->on('item_variants')->restrictOnDelete();
            $t->foreign('item_packaging_id')->references('id')->on('item_packagings')->restrictOnDelete();
            $t->index('item_id');
        });
        Pg::enumCheck('item_barcodes', 'symbology', BarcodeSymbology::class);

        Schema::create('item_uom_conversions', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->uuid('item_id');
            $t->uuid('uom_id');
            $t->decimal('conversion_factor', 20, 10);   // 1 uom = factor × base
            $t->unsignedSmallInteger('rounding_precision')->default(6);
            $t->boolean('is_active')->default(true);
            Pg::timestamps($t);
            $t->foreign('item_id')->references('id')->on('items')->restrictOnDelete();
            $t->foreign('uom_id')->references('id')->on('units_of_measure')->restrictOnDelete();
            $t->unique(['item_id', 'uom_id']);
        });
        Pg::check('item_uom_conversions', 'chk_item_uom_conversions_factor', 'conversion_factor > 0');

        Schema::create('item_vendor_pricelists', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->uuid('item_id');
            $t->uuid('item_variant_id')->nullable();
            $t->uuid('supplier_id');
            $t->string('vendor_product_code', 80)->nullable();
            $t->string('vendor_product_name', 200)->nullable();
            $t->decimal('min_qty', 20, 6)->default(0);
            $t->decimal('unit_price', 20, 8);
            $t->string('currency', 3);
            $t->uuid('uom_id');
            $t->date('valid_from')->nullable();
            $t->date('valid_to')->nullable();
            $t->unsignedInteger('lead_time_days')->nullable();
            $t->boolean('is_preferred')->default(false);
            $t->timestampTz('last_purchased_at')->nullable();
            $t->decimal('last_purchase_price', 20, 8)->nullable();
            Pg::timestamps($t);
            $t->foreign('item_id')->references('id')->on('items')->restrictOnDelete();
            $t->foreign('item_variant_id')->references('id')->on('item_variants')->restrictOnDelete();
            $t->foreign('supplier_id')->references('id')->on('partners')->restrictOnDelete();
            $t->foreign('uom_id')->references('id')->on('units_of_measure')->restrictOnDelete();
            $t->index(['item_id', 'supplier_id']);
        });
        Pg::check('item_vendor_pricelists', 'chk_item_vendor_pricelists_dates', 'valid_from IS NULL OR valid_to IS NULL OR valid_from <= valid_to');
        Pg::check('item_vendor_pricelists', 'chk_item_vendor_pricelists_price', 'unit_price >= 0 AND min_qty >= 0');

        Schema::create('item_reorder_rules', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->uuid('item_id');
            $t->uuid('item_variant_id')->nullable();
            $t->uuid('warehouse_id')->nullable();
            $t->decimal('min_qty', 20, 6)->default(0);
            $t->decimal('max_qty', 20, 6)->nullable();
            $t->decimal('reorder_point', 20, 6)->default(0);
            $t->decimal('reorder_qty', 20, 6)->nullable();
            $t->decimal('safety_stock', 20, 6)->default(0);
            $t->decimal('multiple_of_qty', 20, 6)->nullable();
            $t->uuid('preferred_supplier_id')->nullable();
            $t->uuid('preferred_source_warehouse_id')->nullable();
            Pg::enumColumn($t, 'replenishment_method', default: ReplenishmentMethod::PURCHASE->value);
            $t->unsignedInteger('lead_time_days')->nullable();
            $t->unsignedInteger('review_period_days')->nullable();
            $t->boolean('is_active')->default(true);
            Pg::actorColumns($t);
            Pg::timestamps($t);
            $t->foreign('item_id')->references('id')->on('items')->restrictOnDelete();
            $t->foreign('item_variant_id')->references('id')->on('item_variants')->restrictOnDelete();
            $t->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $t->foreign('preferred_supplier_id')->references('id')->on('partners')->restrictOnDelete();
            $t->foreign('preferred_source_warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
        });
        Pg::enumCheck('item_reorder_rules', 'replenishment_method', ReplenishmentMethod::class);
        Pg::check('item_reorder_rules', 'chk_item_reorder_rules_bounds', 'max_qty IS NULL OR (min_qty <= reorder_point AND reorder_point <= max_qty)');
        DB::statement("CREATE UNIQUE INDEX item_reorder_rules_identity_uq ON item_reorder_rules (item_id, COALESCE(item_variant_id, '00000000-0000-7000-8000-000000000000'::uuid), COALESCE(warehouse_id, '00000000-0000-7000-8000-000000000000'::uuid))");

        Schema::create('item_kit_components', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->uuid('kit_item_id');
            $t->uuid('component_item_id');
            $t->uuid('component_variant_id')->nullable();
            $t->decimal('quantity', 20, 6);
            $t->uuid('uom_id');
            Pg::timestamps($t);
            $t->foreign('kit_item_id')->references('id')->on('items')->restrictOnDelete();
            $t->foreign('component_item_id')->references('id')->on('items')->restrictOnDelete();
            $t->foreign('uom_id')->references('id')->on('units_of_measure')->restrictOnDelete();
            $t->unique(['kit_item_id', 'component_item_id', 'component_variant_id']);
        });
        Pg::check('item_kit_components', 'chk_item_kit_components', 'kit_item_id <> component_item_id AND quantity > 0');
    }

    public function down(): void
    {
        foreach (['item_kit_components', 'item_reorder_rules', 'item_vendor_pricelists', 'item_uom_conversions', 'item_barcodes',
            'item_packagings', 'item_variants', 'item_attribute_lines', 'item_attribute_values', 'item_attributes', 'item_images',
            'item_tags', 'items', 'qc_checklist_templates', 'tags', 'item_category_moves', 'item_category_translations',
            'item_categories', 'units_of_measure', 'uom_categories'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
