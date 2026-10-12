<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Schemas;

use App\Domains\Catalog\Enums\AbcClass;
use App\Domains\Catalog\Enums\CostingMethod;
use App\Domains\Catalog\Enums\ExpiryPolicy;
use App\Domains\Catalog\Enums\ItemStatus;
use App\Domains\Catalog\Enums\ItemType;
use App\Domains\Catalog\Enums\LotNumberPolicy;
use App\Domains\Catalog\Enums\OutOfStockBehavior;
use App\Domains\Catalog\Enums\QcPolicy;
use App\Domains\Catalog\Enums\ReceiptControlPolicy;
use App\Domains\Catalog\Enums\RemovalStrategy;
use App\Domains\Catalog\Enums\TrackingMode;
use App\Domains\Catalog\Enums\WarningMode;
use App\Domains\Catalog\Models\ItemCategory;
use App\Domains\Catalog\Models\QcChecklistTemplate;
use App\Domains\Catalog\Models\UnitOfMeasure;
use App\Domains\IdentityAccess\Models\Person;
use App\Domains\Organization\Models\StorageCategory;
use App\Domains\Partners\Models\Partner;
use App\Domains\Platform\Models\LookupValue;
use App\Support\Enums\DataClassification;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/**
 * Item master with Odoo-level detail (Chapter 06 §6.6, Appendix D).
 */
final class ItemSchema extends TableSchema
{
    public static function table(): string
    {
        return 'items';
    }

    public static function stateEnum(): ?string
    {
        return ItemStatus::class;
    }

    protected static function define(): array
    {
        $uom = static fn (string $label) => Property::uuid()->nullable()->references(UnitOfMeasure::class, 'status', ['active'])->label($label);
        $cost = DataClassification::COST_SENSITIVE->value;
        $days = static fn (string $label) => Property::integer()->nullable()->min(0)->max(36500)->label($label);
        $qty = static fn (string $label) => Property::quantity()->nullable()->nonNegative()->label($label);

        return [
            // D.1 General
            'sku' => Property::string(64)->nullable()->trim()->pattern('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,63}$/')->unique()->searchable()->sortable()->label('SKU', 'Leave blank to generate from the SKU pattern. Immutable after first posting.'),
            'name' => Property::string(200)->required()->trim()->searchable()->sortable()->label('Name'),
            'item_type' => Property::enum(ItemType::class)->default(ItemType::STOCKABLE->value)->filterable()->label('Item type'),
            'is_sellable' => Property::boolean()->default(true)->filterable()->label('Can be sold'),
            'is_purchasable' => Property::boolean()->default(true)->filterable()->label('Can be purchased'),
            'is_transferable' => Property::boolean()->default(true)->label('Can be transferred'),
            'category_id' => Property::uuid()->required()->references(ItemCategory::class, 'status', ['active'])->filterable()->label('Category', 'Must be a leaf category unless configured otherwise.'),
            'tag_ids' => Property::arrayOf(Property::uuid())->nullable()->max(50)->notReportable()->label('Tags')->virtual(),
            'brand_lookup_value_id' => Property::uuid()->nullable()->references(LookupValue::class)->filterable()->label('Brand'),
            'manufacturer_partner_id' => Property::uuid()->nullable()->references(Partner::class)->label('Manufacturer'),
            'model_number' => Property::string(100)->nullable()->trim()->searchable()->label('Model number'),
            'description_short' => Property::string(500)->nullable()->trim()->label('Short description'),
            'description_long' => Property::text(20000)->nullable()->label('Long description'),
            'internal_notes' => Property::text(4000)->nullable()->notReportable()->label('Internal notes'),
            'status' => Property::enum(ItemStatus::class)->serverControlled()->filterable()->label('Status'),
            'responsible_person_id' => Property::uuid()->nullable()->references(Person::class)->label('Responsible'),
            'is_critical' => Property::boolean()->default(false)->filterable()->label('Critical item'),
            'requires_approval_for_issue' => Property::boolean()->nullable()->label('Issue requires approval', 'Blank = inherit from category.'),
            // D.2 Identification
            'barcodes' => Property::arrayOf(ItemBarcodeLineSchema::class)->nullable()->max(50)->writableBy('catalog.barcode.manage')->notReportable()->label('Barcodes')->virtual(),
            'hs_code' => Property::string(20)->nullable()->trim()->label('HS code'),
            'country_of_origin' => Property::string(2)->nullable()->pattern('/^[A-Z]{2}$/')->label('Country of origin'),
            'customs_description' => Property::string(255)->nullable()->label('Customs description'),
            // D.3 Units & packaging
            'base_uom_id' => Property::uuid()->requiredOnCreate()->references(UnitOfMeasure::class, 'status', ['active'])->label('Base unit', 'Immutable after first posting.'),
            'purchase_uom_id' => $uom('Purchase unit'),
            'sales_uom_id' => $uom('Sales unit'),
            'transfer_uom_id' => $uom('Transfer unit'),
            'uom_conversions' => Property::arrayOf(ItemUomConversionLineSchema::class)->nullable()->max(20)->notReportable()->label('Unit conversions')->virtual(),
            'packagings' => Property::arrayOf(ItemPackagingLineSchema::class)->nullable()->max(20)->writableBy('catalog.packaging.manage')->notReportable()->label('Packagings')->virtual(),
            'weight' => Property::decimal(12, 4)->nullable()->nonNegative()->label('Weight'),
            'weight_uom_id' => $uom('Weight unit'),
            'volume' => Property::decimal(12, 6)->nullable()->nonNegative()->label('Volume'),
            'volume_uom_id' => $uom('Volume unit'),
            'length' => Property::decimal(12, 4)->nullable()->nonNegative()->label('Length'),
            'width' => Property::decimal(12, 4)->nullable()->nonNegative()->label('Width'),
            'height' => Property::decimal(12, 4)->nullable()->nonNegative()->label('Height'),
            'dimension_uom_id' => $uom('Dimension unit'),
            // D.4 Inventory & logistics
            'tracking_mode' => Property::enum(TrackingMode::class)->nullable()->filterable()->label('Tracking', 'Blank = category default. Immutable after first posting.'),
            'lot_number_policy' => Property::enum(LotNumberPolicy::class)->default(LotNumberPolicy::EITHER->value)->label('Lot numbers'),
            'expiry_policy' => Property::enum(ExpiryPolicy::class)->default(ExpiryPolicy::INHERIT_CATEGORY->value)->filterable()->label('Expiry'),
            'shelf_life_days' => $days('Shelf life (days)'),
            'best_before_offset_days' => $days('Best-before offset (days)'),
            'removal_offset_days' => $days('Removal offset (days)'),
            'alert_offset_days' => $days('Alert offset (days)'),
            'removal_strategy' => Property::enum(RemovalStrategy::class)->default(RemovalStrategy::INHERIT_CATEGORY->value)->label('Removal strategy'),
            'quality_control_policy' => Property::enum(QcPolicy::class)->default(QcPolicy::INHERIT_CATEGORY->value)->label('Quality control'),
            'qc_checklist_template_id' => Property::uuid()->nullable()->references(QcChecklistTemplate::class)->label('QC checklist'),
            'storage_category_id' => Property::uuid()->nullable()->references(StorageCategory::class)->label('Storage category'),
            'preferred_storage_conditions' => Property::json()->nullable()->label('Storage conditions'),
            'is_hazardous' => Property::boolean()->default(false)->filterable()->label('Hazardous'),
            'is_fragile' => Property::boolean()->default(false)->label('Fragile'),
            'is_perishable' => Property::boolean()->default(false)->filterable()->label('Perishable'),
            'abc_class' => Property::enum(AbcClass::class)->default(AbcClass::UNCLASSIFIED->value)->filterable()->label('ABC class'),
            'abc_override' => Property::boolean()->default(false)->label('Manual ABC class'),
            'counting_frequency_days' => $days('Counting frequency (days)'),
            'supplier_lead_time_days' => $days('Supplier lead time (days)'),
            'customer_lead_time_days' => $days('Customer lead time (days)'),
            'receipt_description' => Property::string(500)->nullable()->label('Receipt note'),
            'delivery_description' => Property::string(500)->nullable()->label('Delivery note'),
            'transfer_description' => Property::string(500)->nullable()->label('Transfer note'),
            'allow_negative_stock_override' => Property::boolean()->default(false)->writableBy('inventory.negative_stock.override')->label('Allow negative stock override'),
            'min_order_qty' => $qty('Minimum order quantity'),
            'max_order_qty' => $qty('Maximum order quantity'),
            'order_multiple' => $qty('Order multiple'),
            // D.6 Purchasing
            'purchase_description' => Property::string(500)->nullable()->label('Purchase description'),
            'purchase_warning_lookup_value_id' => Property::uuid()->nullable()->references(LookupValue::class)->label('Purchase warning'),
            'purchase_warning_mode' => Property::enum(WarningMode::class)->default(WarningMode::NONE->value)->label('Purchase warning mode'),
            'receipt_control_policy' => Property::enum(ReceiptControlPolicy::class)->default(ReceiptControlPolicy::RECEIVED_QTY->value)->label('Receipt control'),
            'last_purchase_cost' => Property::unitCost()->nullable()->serverControlled()->classification($cost)->label('Last purchase cost'),
            'last_purchased_at' => Property::datetime()->nullable()->serverControlled()->label('Last purchased'),
            // D.7 Sales (stock-side)
            'list_price' => Property::money()->nullable()->nonNegative()->label('List price'),
            'list_price_currency' => Property::string(3)->nullable()->pattern('/^[A-Z]{3}$/')->label('List price currency'),
            'sales_description' => Property::string(500)->nullable()->label('Sales description'),
            'sales_warning_lookup_value_id' => Property::uuid()->nullable()->references(LookupValue::class)->label('Sales warning'),
            'sales_warning_mode' => Property::enum(WarningMode::class)->default(WarningMode::NONE->value)->label('Sales warning mode'),
            'out_of_stock_behavior' => Property::enum(OutOfStockBehavior::class)->default(OutOfStockBehavior::BLOCK->value)->label('Out-of-stock behaviour'),
            'is_available_online' => Property::boolean()->default(false)->label('Available online'),
            // D.8 Costing & valuation
            'costing_method' => Property::enum(CostingMethod::class)->default(CostingMethod::INHERIT_CATEGORY->value)->writableBy('catalog.item.manage_cost')->classification($cost)->label('Costing method', 'Immutable after first posting.'),
            'standard_cost' => Property::unitCost()->nullable()->nonNegative()->writableBy('catalog.item.manage_cost')->classification($cost)->label('Standard cost'),
            'standard_cost_currency' => Property::string(3)->nullable()->pattern('/^[A-Z]{3}$/')->writableBy('catalog.item.manage_cost')->classification($cost)->label('Standard cost currency'),
            'standard_cost_valid_from' => Property::date()->nullable()->writableBy('catalog.item.manage_cost')->classification($cost)->label('Standard cost valid from'),
            'current_average_cost' => Property::unitCost()->serverControlled()->classification($cost)->label('Average cost'),
            'valuation_account_ref' => Property::string(64)->nullable()->writableBy('catalog.item.manage_cost')->label('Valuation account ref'),
            'expense_account_ref' => Property::string(64)->nullable()->writableBy('catalog.item.manage_cost')->label('Expense account ref'),
            'price_difference_account_ref' => Property::string(64)->nullable()->writableBy('catalog.item.manage_cost')->label('Price difference account ref'),
            'cost_visibility_classification' => Property::enum(DataClassification::class)->default(DataClassification::COST_SENSITIVE->value)->writableBy('catalog.item.manage_cost')->label('Cost visibility'),
            // D.9 Variants, D.10 kits
            'attribute_lines' => Property::arrayOf(ItemAttributeLineSchema::class)->nullable()->max(10)->writableBy('catalog.variant.manage')->notReportable()->label('Variant attributes')->virtual(),
            'kit_components' => Property::arrayOf(ItemKitComponentLineSchema::class)->nullable()->max(100)->writableBy('catalog.kit.manage')->notReportable()->label('Kit components')->virtual(),
            'has_variants' => Property::boolean()->serverControlled()->label('Has variants'),
            // Bookkeeping
            'first_posted_at' => Property::datetime()->nullable()->serverControlled()->label('First posted'),
            'archived_at' => Property::datetime()->nullable()->serverControlled(),
            'version' => Property::integer()->nullable()->min(1)->notReportable()->label('Version', 'Optimistic concurrency version (required on update).')->virtual(),
        ];
    }
}
