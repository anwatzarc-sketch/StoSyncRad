<?php

declare(strict_types=1);

namespace App\Domains\CustomerPortal\Services;

use App\Domains\Catalog\Enums\ItemStatus;
use App\Domains\Catalog\Models\Item;
use App\Domains\CustomerPortal\Enums\AvailabilityDisplay;
use App\Domains\Inventory\Enums\StockCondition;
use App\Domains\Organization\Enums\InventoryLocationType;
use App\Domains\Platform\Config\PlatformConfig;
use App\Support\Database\Search;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Math\Decimal as D;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The customer view of the catalogue (CP-1.1 section 5.1): active, sellable items published online
 * (`is_available_online`), with their list price and an availability band for the ordering warehouse.
 * Costs, stock locations, lots and other customers' terms are never exposed.
 */
final class PortalCatalog
{
    public function __construct(private readonly PlatformConfig $config) {}

    /** @return Builder<Item> */
    public function visibleItems(): Builder
    {
        return Item::query()->where('status', ItemStatus::ACTIVE->value)->where('is_sellable', true)->where('is_available_online', true);
    }

    /** @return array{data: array<int, array<string, mixed>>, meta: array<string, int>} */
    public function search(?string $term, ?string $categoryId, int $page = 1, int $perPage = 24): array
    {
        $query = $this->visibleItems()
            ->when($term !== null && trim($term) !== '', fn ($q) => $q->where(fn ($w) => $w->whereRaw(Search::like('name'), ['%'.trim((string) $term).'%'])
                ->orWhereRaw(Search::like('sku'), ['%'.trim((string) $term).'%'])->orWhereRaw(Search::like('description_short'), ['%'.trim((string) $term).'%'])))
            ->when($categoryId !== null && $categoryId !== '', fn ($q) => $q->whereIn('category_id', $this->categoryAndDescendants((string) $categoryId)))
            ->orderBy('name');
        $total = (clone $query)->count();
        $perPage = max(1, min(100, $perPage));
        $items = $query->forPage(max(1, $page), $perPage)->get();

        return ['data' => $this->present($items->all()), 'meta' => ['page' => max(1, $page), 'per_page' => $perPage, 'total' => $total]];
    }

    /** @return array<string, mixed> */
    public function item(string $id): array
    {
        $item = $this->visibleItems()->whereKey($id)->first() ?? throw DomainException::notFound('product');

        return $this->present([$item])[0] + ['description_long' => $item->sales_description ?: $item->description_long];
    }

    /** Categories that contain visible items (for filters and the optional public preview). @return array<int, array<string, mixed>> */
    public function categories(): array
    {
        return DB::table('item_categories as c')->joinSub($this->visibleItems()->toBase()->select('category_id', DB::raw('count(*) as n'))->groupBy('category_id'), 'v', 'v.category_id', '=', 'c.id')
            ->orderBy('c.full_path')->get(['c.id', 'c.name', 'c.full_path', 'v.n as item_count'])->map(fn ($r) => (array) $r)->all();
    }

    /** List price, or null when the item must be quoted. */
    public function price(Item $item): ?string
    {
        return $item->list_price === null ? null : D::exact((string) $item->list_price, D::MONEY_SCALE);
    }

    public function currency(Item $item): string
    {
        return (string) ($item->list_price_currency ?: $this->config->get('locale.currency.base'));
    }

    /**
     * Server-side order quantity rules (minimum, maximum, multiple) for one line.
     */
    public function assertOrderable(Item $item, string $quantity, int $line): void
    {
        $field = ['field' => "lines.$line.quantity"];
        if (D::lte($quantity, '0')) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Quantities must be greater than zero.', $field);
        }
        if ($item->min_order_qty !== null && D::lt($quantity, (string) $item->min_order_qty)) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, "{$item->name}: the minimum order is {$this->trim($item->min_order_qty)}.", $field);
        }
        if ($item->max_order_qty !== null && D::gt($quantity, (string) $item->max_order_qty)) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, "{$item->name}: the maximum per order is {$this->trim($item->max_order_qty)}.", $field);
        }
        if ($item->order_multiple !== null && D::gt((string) $item->order_multiple, '0') && ! D::isWhole(D::div($quantity, (string) $item->order_multiple, D::QTY_SCALE))) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, "{$item->name} is sold in multiples of {$this->trim($item->order_multiple)}.", $field);
        }
    }

    /**
     * @param  array<int, Item>  $items
     * @return array<int, array<string, mixed>>
     */
    private function present(array $items): array
    {
        if ($items === []) {
            return [];
        }
        $ids = array_map(fn (Item $i) => $i->id, $items);
        $units = DB::table('units_of_measure')->whereIn('id', array_map(fn (Item $i) => $i->base_uom_id, $items))->get(['id', 'name', 'symbol'])->keyBy('id');
        $categories = DB::table('item_categories')->whereIn('id', array_map(fn (Item $i) => $i->category_id, $items))->pluck('name', 'id');
        $images = DB::table('item_images')->whereIn('item_id', $ids)->orderByDesc('is_primary')->orderBy('sort_order')->get(['item_id'])->keyBy('item_id');
        $availability = $this->availability($ids);
        $showPrices = $this->config->bool('portal.catalog.show_prices');
        $direct = $this->config->bool('portal.orders.direct_ordering');

        return array_map(function (Item $item) use ($units, $categories, $images, $availability, $showPrices, $direct) {
            $price = $showPrices ? $this->price($item) : null;

            return [
                'id' => $item->id, 'sku' => $item->sku, 'name' => $item->name, 'description' => $item->description_short,
                'category' => $categories[$item->category_id] ?? null, 'category_id' => $item->category_id,
                'unit' => $units[$item->base_uom_id]->symbol ?? null, 'unit_name' => $units[$item->base_uom_id]->name ?? null,
                'price' => $price, 'currency' => $price === null ? null : $this->currency($item),
                'availability' => $availability[$item->id] ?? null,
                'min_order_qty' => $this->trim($item->min_order_qty), 'max_order_qty' => $this->trim($item->max_order_qty), 'order_multiple' => $this->trim($item->order_multiple),
                'image_url' => isset($images[$item->id]) ? "/api/v1/portal/catalog/items/{$item->id}/image" : null,
                'orderable' => $direct && $item->list_price !== null, 'quotable' => true,
            ];
        }, $items);
    }

    /** @param  array<int, string>  $itemIds  @return array<string, array<string, mixed>> */
    private function availability(array $itemIds): array
    {
        $mode = AvailabilityDisplay::from((string) $this->config->get('portal.catalog.availability'));
        $warehouse = $this->config->get('portal.orders.warehouse_id');
        if ($mode === AvailabilityDisplay::HIDDEN || $warehouse === null) {
            return [];
        }
        $available = DB::table('inventory_balances as b')->join('inventory_locations as il', 'il.id', '=', 'b.inventory_location_id')
            ->whereIn('b.item_id', $itemIds)->where('b.warehouse_id', $warehouse)->where('b.stock_condition', StockCondition::AVAILABLE->value)
            ->where('il.location_type', InventoryLocationType::PHYSICAL->value)->groupBy('b.item_id')
            ->selectRaw('b.item_id, COALESCE(SUM(b.quantity_on_hand - b.quantity_reserved), 0) AS q')->pluck('q', 'item_id');
        $low = (string) $this->config->int('portal.catalog.low_stock_threshold');
        $out = [];
        foreach ($itemIds as $id) {
            $q = D::exact((string) ($available[$id] ?? '0'));
            $band = D::lte($q, '0') ? 'out_of_stock' : (D::lte($q, $low) ? 'low_stock' : 'in_stock');
            $out[$id] = $mode === AvailabilityDisplay::QUANTITY ? ['band' => $band, 'quantity' => $this->trim($q)] : ['band' => $band];
        }

        return $out;
    }

    /** @return array<int, string> */
    private function categoryAndDescendants(string $categoryId): array
    {
        $path = DB::table('item_categories')->where('id', $categoryId)->value('code_path');

        return $path === null ? [$categoryId] : DB::table('item_categories')->where('code_path', $path)->orWhere('code_path', 'like', $path.'/%')->pluck('id')->all();
    }

    private function trim(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = (string) $value;

        return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
    }
}
