<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Services;

use App\Domains\Catalog\Enums\CostingMethod;
use App\Domains\Catalog\Enums\ExpiryPolicy;
use App\Domains\Catalog\Enums\QcPolicy;
use App\Domains\Catalog\Enums\RemovalStrategy;
use App\Domains\Catalog\Enums\TrackingMode;
use App\Domains\Catalog\Models\Item;
use App\Domains\Catalog\Models\ItemCategory;
use App\Domains\Platform\Config\PlatformConfig;
use BackedEnum;
use Illuminate\Support\Facades\DB;

/**
 * Resolves effective item policies (Chapter 06 §6.7):
 *   item value (unless `inherit_category`/null) → nearest ancestor category default → config default.
 *
 * Category rows are loaded once per instance; the resolver is request-scoped (not a singleton).
 */
final class CategoryPolicyResolver
{
    /** @var array<string, object>|null category id → row */
    private ?array $categories = null;

    public function __construct(private readonly PlatformConfig $config) {}

    /** Tracking mode is always concrete on the item (resolved at creation, immutable after posting). */
    public function trackingMode(Item $item): TrackingMode
    {
        return $item->tracking_mode;
    }

    /** Default tracking mode for a new item in this category. */
    public function defaultTrackingModeFor(string $categoryId): TrackingMode
    {
        return TrackingMode::from((string) ($this->inherited($categoryId, 'default_tracking_mode')
            ?? $this->config->get('catalog.default_tracking_mode', ['category' => $categoryId])));
    }

    public function expiryPolicy(Item $item): ExpiryPolicy
    {
        if ($item->expiry_policy !== ExpiryPolicy::INHERIT_CATEGORY) {
            return $item->expiry_policy;
        }
        $value = $this->inherited($item->category_id, 'default_expiry_policy', [ExpiryPolicy::INHERIT_CATEGORY->value])
            ?? $this->config->get('catalog.default_expiry_policy', ['category' => $item->category_id]);

        return ExpiryPolicy::from((string) $value);
    }

    public function removalStrategy(Item $item): RemovalStrategy
    {
        if ($item->removal_strategy !== RemovalStrategy::INHERIT_CATEGORY) {
            return $item->removal_strategy;
        }
        $value = $this->inherited($item->category_id, 'default_removal_strategy', [RemovalStrategy::INHERIT_CATEGORY->value])
            ?? $this->config->get('catalog.default_removal_strategy', ['category' => $item->category_id]);

        return RemovalStrategy::from((string) $value);
    }

    public function qcPolicy(Item $item): QcPolicy
    {
        if ($item->quality_control_policy !== QcPolicy::INHERIT_CATEGORY) {
            return $item->quality_control_policy;
        }
        $value = $this->inherited($item->category_id, 'default_qc_policy', [QcPolicy::INHERIT_CATEGORY->value])
            ?? $this->config->get('catalog.default_qc_policy', ['category' => $item->category_id]);

        return QcPolicy::from((string) $value);
    }

    public function costingMethod(Item $item): CostingMethod
    {
        if ($item->costing_method !== CostingMethod::INHERIT_CATEGORY) {
            return $item->costing_method;
        }
        $value = $this->inherited($item->category_id, 'default_costing_method', [CostingMethod::INHERIT_CATEGORY->value])
            ?? $this->config->get('inventory.costing_method', ['category' => $item->category_id, 'item' => $item->id]);
        $method = CostingMethod::from((string) $value);

        return $method === CostingMethod::INHERIT_CATEGORY ? CostingMethod::MOVING_AVERAGE : $method;
    }

    public function requiresApprovalForIssue(Item $item): bool
    {
        if ($item->requires_approval_for_issue !== null) {
            return $item->requires_approval_for_issue;
        }

        return (bool) ($this->inherited($item->category_id, 'requires_approval_for_issue') ?? false);
    }

    /**
     * Full explanation for the UI: value + where it came from.
     *
     * @return array<string, array{value: mixed, source: string}>
     */
    public function explain(Item $item): array
    {
        $out = [];
        foreach ([
            'tracking_mode' => fn () => $this->trackingMode($item),
            'expiry_policy' => fn () => $this->expiryPolicy($item),
            'removal_strategy' => fn () => $this->removalStrategy($item),
            'quality_control_policy' => fn () => $this->qcPolicy($item),
            'costing_method' => fn () => $this->costingMethod($item),
        ] as $field => $resolve) {
            $raw = $item->getRawOriginal($field) ?? $item->getAttributes()[$field] ?? null;
            $isInherited = $raw === null || $raw === 'inherit_category';
            $value = $resolve();
            $out[$field] = ['value' => $value instanceof BackedEnum ? $value->value : $value, 'source' => $isInherited ? 'category_or_config' : 'item'];
        }

        return $out;
    }

    /**
     * Walk from the category up to the root, returning the first non-null (and non-excluded) value.
     *
     * @param  array<int, string>  $exclude  values that mean "inherit"
     */
    public function inherited(string $categoryId, string $column, array $exclude = []): mixed
    {
        $categories = $this->categories();
        $id = $categoryId;
        $guard = 0;
        while ($id !== null && isset($categories[$id]) && $guard++ < 64) {
            $value = $categories[$id]->{$column} ?? null;
            if ($value !== null && ! in_array($value, $exclude, true)) {
                return is_bool($value) || ! is_string($value) ? $value : $value;
            }
            $id = $categories[$id]->parent_id;
        }

        return null;
    }

    /** @return array<string, object> */
    private function categories(): array
    {
        if ($this->categories !== null) {
            return $this->categories;
        }
        $rows = DB::table((new ItemCategory)->getTable())->get([
            'id', 'parent_id', 'default_tracking_mode', 'default_expiry_policy', 'default_removal_strategy', 'default_qc_policy',
            'default_costing_method', 'default_uom_id', 'default_counting_frequency_days', 'default_storage_category_id',
            'default_abc_class', 'requires_approval_for_issue', 'is_hazardous', 'cost_visibility_classification',
        ]);

        return $this->categories = $rows->keyBy('id')->all();
    }
}
