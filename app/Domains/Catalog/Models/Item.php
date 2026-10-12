<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Models;

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
use App\Domains\Catalog\Schemas\ItemSchema;
use App\Support\Eloquent\BaseModel;
use App\Support\Enums\DataClassification;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Item master (Chapter 06 §6.6, Appendix D). Policy fields set to `inherit_category` (or null
 * tracking mode) are resolved through CategoryPolicyResolver.
 *
 * @property string $sku
 * @property string $name
 * @property ItemType $item_type
 * @property bool $is_sellable
 * @property bool $is_purchasable
 * @property bool $is_transferable
 * @property string $category_id
 * @property ItemStatus $status
 * @property bool $is_critical
 * @property bool|null $requires_approval_for_issue
 * @property string $base_uom_id
 * @property string|null $purchase_uom_id
 * @property string|null $sales_uom_id
 * @property string|null $transfer_uom_id
 * @property TrackingMode $tracking_mode
 * @property LotNumberPolicy $lot_number_policy
 * @property ExpiryPolicy $expiry_policy
 * @property int|null $shelf_life_days
 * @property int|null $best_before_offset_days
 * @property int|null $removal_offset_days
 * @property int|null $alert_offset_days
 * @property RemovalStrategy $removal_strategy
 * @property QcPolicy $quality_control_policy
 * @property string|null $qc_checklist_template_id
 * @property AbcClass $abc_class
 * @property bool $allow_negative_stock_override
 * @property OutOfStockBehavior $out_of_stock_behavior
 * @property CostingMethod $costing_method
 * @property string|null $standard_cost
 * @property string $current_average_cost
 * @property string|null $last_purchase_cost
 * @property DataClassification $cost_visibility_classification
 * @property bool $has_variants
 * @property \Carbon\CarbonImmutable|null $first_posted_at
 * @property int $version
 * @property-read ItemCategory $category
 * @property-read UnitOfMeasure $baseUom
 */
final class Item extends BaseModel
{
    protected $table = 'items';

    protected static ?string $schema = ItemSchema::class;

    /** Cost-classified attributes hidden from serialization unless the viewer may see costs. */
    public const COST_ATTRIBUTES = ['standard_cost', 'standard_cost_currency', 'standard_cost_valid_from', 'current_average_cost',
        'last_purchase_cost', 'valuation_account_ref', 'expense_account_ref', 'price_difference_account_ref'];

    protected function casts(): array
    {
        return [
            'item_type' => ItemType::class,
            'status' => ItemStatus::class,
            'tracking_mode' => TrackingMode::class,
            'lot_number_policy' => LotNumberPolicy::class,
            'expiry_policy' => ExpiryPolicy::class,
            'removal_strategy' => RemovalStrategy::class,
            'quality_control_policy' => QcPolicy::class,
            'abc_class' => AbcClass::class,
            'purchase_warning_mode' => WarningMode::class,
            'receipt_control_policy' => ReceiptControlPolicy::class,
            'sales_warning_mode' => WarningMode::class,
            'out_of_stock_behavior' => OutOfStockBehavior::class,
            'costing_method' => CostingMethod::class,
            'cost_visibility_classification' => DataClassification::class,
            'is_sellable' => 'boolean',
            'is_purchasable' => 'boolean',
            'is_transferable' => 'boolean',
            'is_critical' => 'boolean',
            'requires_approval_for_issue' => 'boolean',
            'is_hazardous' => 'boolean',
            'is_fragile' => 'boolean',
            'is_perishable' => 'boolean',
            'abc_override' => 'boolean',
            'allow_negative_stock_override' => 'boolean',
            'is_available_online' => 'boolean',
            'has_variants' => 'boolean',
            'preferred_storage_conditions' => 'array',
            'shelf_life_days' => 'integer',
            'best_before_offset_days' => 'integer',
            'removal_offset_days' => 'integer',
            'alert_offset_days' => 'integer',
            'counting_frequency_days' => 'integer',
            'supplier_lead_time_days' => 'integer',
            'customer_lead_time_days' => 'integer',
            'standard_cost_valid_from' => 'immutable_date',
            'last_purchased_at' => 'immutable_datetime',
            'first_posted_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    // ---- Relations -----------------------------------------------------------------------

    public function category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'category_id');
    }

    public function baseUom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'base_uom_id');
    }

    public function barcodes(): HasMany
    {
        return $this->hasMany(ItemBarcode::class);
    }

    public function uomConversions(): HasMany
    {
        return $this->hasMany(ItemUomConversion::class);
    }

    public function packagings(): HasMany
    {
        return $this->hasMany(ItemPackaging::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ItemVariant::class);
    }

    public function attributeLines(): HasMany
    {
        return $this->hasMany(ItemAttributeLine::class);
    }

    public function vendorPricelists(): HasMany
    {
        return $this->hasMany(ItemVendorPricelist::class);
    }

    public function reorderRules(): HasMany
    {
        return $this->hasMany(ItemReorderRule::class);
    }

    public function kitComponents(): HasMany
    {
        return $this->hasMany(ItemKitComponent::class, 'kit_item_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ItemImage::class)->orderBy('sort_order');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'item_tags')->using(ItemTag::class)->withPivot('id');
    }

    // ---- State helpers -------------------------------------------------------------------

    public function hasPostings(): bool
    {
        return $this->first_posted_at !== null;
    }

    public function isActive(): bool
    {
        return $this->status === ItemStatus::ACTIVE;
    }

    public function isStockTracked(): bool
    {
        return $this->item_type->isStockTracked();
    }

    public function label(): string
    {
        return "{$this->sku} — {$this->name}";
    }
}
