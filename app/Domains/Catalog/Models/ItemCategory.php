<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Models;

use App\Domains\Catalog\Enums\AbcClass;
use App\Domains\Catalog\Enums\CategoryStatus;
use App\Domains\Catalog\Enums\CostingMethod;
use App\Domains\Catalog\Enums\ExpiryPolicy;
use App\Domains\Catalog\Enums\QcPolicy;
use App\Domains\Catalog\Enums\RemovalStrategy;
use App\Domains\Catalog\Enums\TrackingMode;
use App\Domains\Catalog\Schemas\ItemCategorySchema;
use App\Support\Eloquent\BaseModel;
use App\Support\Enums\DataClassification;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Hierarchical product category with inheritable policy defaults (Chapter 06 §6.7).
 *
 * @property string|null $parent_id
 * @property string $code
 * @property string $name
 * @property string $full_path   "Electrical / Cables / Low Voltage"
 * @property string $code_path   "ELEC/ELEC.CABLE/ELEC.CABLE.LV"
 * @property int $depth
 * @property int $sort_order
 * @property CategoryStatus $status
 * @property TrackingMode|null $default_tracking_mode
 * @property ExpiryPolicy|null $default_expiry_policy
 * @property RemovalStrategy|null $default_removal_strategy
 * @property QcPolicy|null $default_qc_policy
 * @property CostingMethod|null $default_costing_method
 * @property string|null $default_uom_id
 * @property int|null $default_counting_frequency_days
 * @property string|null $default_storage_category_id
 * @property AbcClass|null $default_abc_class
 * @property bool|null $requires_approval_for_issue
 * @property bool|null $is_hazardous
 * @property DataClassification|null $cost_visibility_classification
 */
final class ItemCategory extends BaseModel
{
    public const PATH_SEPARATOR = ' / ';

    protected $table = 'item_categories';

    protected static ?string $schema = ItemCategorySchema::class;

    protected function casts(): array
    {
        return [
            'status' => CategoryStatus::class,
            'default_tracking_mode' => TrackingMode::class,
            'default_expiry_policy' => ExpiryPolicy::class,
            'default_removal_strategy' => RemovalStrategy::class,
            'default_qc_policy' => QcPolicy::class,
            'default_costing_method' => CostingMethod::class,
            'default_abc_class' => AbcClass::class,
            'cost_visibility_classification' => DataClassification::class,
            'requires_approval_for_issue' => 'boolean',
            'is_hazardous' => 'boolean',
            'depth' => 'integer',
            'sort_order' => 'integer',
            'default_counting_frequency_days' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'category_id');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(ItemCategoryTranslation::class);
    }

    public function isLeaf(): bool
    {
        return ! self::query()->where('parent_id', $this->id)->exists();
    }

    public function isActive(): bool
    {
        return $this->status === CategoryStatus::ACTIVE;
    }

    /** @return array<int, string> ancestor ids, root first, excluding self */
    public function ancestorIds(): array
    {
        $ids = [];
        $parentId = $this->parent_id;
        $guard = 0;
        while ($parentId !== null && $guard++ < 64) {
            $ids[] = $parentId;
            $parentId = self::query()->whereKey($parentId)->value('parent_id');
        }

        return array_reverse($ids);
    }
}
