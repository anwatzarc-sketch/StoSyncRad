<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Actions;

use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Catalog\Enums\CategoryStatus;
use App\Domains\Catalog\Enums\ItemStatus;
use App\Domains\Catalog\Enums\ItemType;
use App\Domains\Catalog\Enums\TrackingMode;
use App\Domains\Catalog\Models\Item;
use App\Domains\Catalog\Models\ItemCategory;
use App\Domains\Catalog\Schemas\ItemSchema;
use App\Domains\Catalog\Services\CategoryPolicyResolver;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Messaging\Services\Outbox;
use App\Domains\Platform\Config\PlatformConfig;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Math\Decimal as D;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Create/update an item with its Odoo-level detail (Appendix D) and child collections: barcodes,
 * unit conversions, packagings, kit components, tags, variant attributes (variants generated as the
 * cartesian product of attribute values). Base unit, tracking mode and costing method become
 * immutable once the item has posted stock; SKU too when configured.
 */
final class SaveItemAction
{
    private const IMMUTABLE_AFTER_POSTING = ['base_uom_id', 'tracking_mode', 'costing_method'];

    public function __construct(
        private readonly PlatformConfig $config,
        private readonly CategoryPolicyResolver $policies,
        private readonly AuditRecorder $audit,
        private readonly Outbox $outbox,
    ) {}

    /** @param  array<string, mixed>  $data  validated against ItemSchema */
    public function execute(array $data, ?UserAccount $actor, ?string $id = null): Item
    {
        return DB::transaction(function () use ($data, $actor, $id) {
            $creating = $id === null;
            /** @var Item $item */
            $item = $creating ? new Item : Item::query()->lockForUpdate()->findOrFail($id);
            if (! $creating && isset($data['version']) && (int) $data['version'] !== (int) $item->version) {
                throw DomainException::staleVersion((int) $data['version'], (int) $item->version);
            }
            $before = $creating ? null : $item->only(array_keys(ItemSchema::columns($data)));
            $categoryId = $data['category_id'] ?? $item->category_id;
            $category = ItemCategory::query()->findOrFail($categoryId);
            if ($category->status !== CategoryStatus::ACTIVE) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, "Category {$category->full_path} is not active.", ['field' => 'category_id']);
            }
            if (! $this->config->bool('catalog.items_on_non_leaf_categories') && ItemCategory::query()->where('parent_id', $category->id)->exists()) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, "{$category->full_path} has sub-categories; choose a leaf category.", ['field' => 'category_id']);
            }
            if (! $creating && $item->first_posted_at !== null) {
                foreach (self::IMMUTABLE_AFTER_POSTING as $field) {
                    $new = $data[$field] ?? null;
                    $old = $item->getAttribute($field);
                    $old = $old instanceof \BackedEnum ? $old->value : $old;
                    if ($new !== null && $new !== $old) {
                        throw DomainException::of(ErrorCode::POLICY_VIOLATION, Str::headline($field).' cannot change after stock has been posted.',
                            ['field' => $field], $field === 'tracking_mode' ? 'Use the tracking conversion workflow.' : null);
                    }
                }
                if (isset($data['sku']) && $data['sku'] !== $item->sku && $this->config->bool('catalog.sku.immutable_after_posting')) {
                    throw DomainException::of(ErrorCode::POLICY_VIOLATION, 'The SKU cannot change after stock has been posted.', ['field' => 'sku']);
                }
            }

            $item->forceFill(ItemSchema::columns($data));   // schema columns exclude server-controlled fields
            if ($creating) {
                $scope = ['category' => $category->id];
                $item->forceFill([
                    'sku' => ($data['sku'] ?? null) ?: $this->generateSku($category),
                    'status' => ItemStatus::ACTIVE,
                    'tracking_mode' => isset($data['tracking_mode']) ? TrackingMode::from($data['tracking_mode']) : $this->policies->defaultTrackingModeFor($category->id),
                    'created_by' => $actor?->id,
                ]);
                if (empty($data['base_uom_id']) && $category->default_uom_id !== null) {
                    $item->base_uom_id = $category->default_uom_id;
                }
            }
            if ($item->item_type === ItemType::SERVICE && $item->tracking_mode !== TrackingMode::NONE) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Services cannot be lot or serial tracked.', ['field' => 'tracking_mode']);
            }
            if (Item::query()->where('sku', $item->sku)->when(! $creating, fn ($q) => $q->where('id', '<>', $item->id))->exists()) {
                throw DomainException::of(ErrorCode::DUPLICATE_RESOURCE, "SKU {$item->sku} is already used.", ['field' => 'sku']);
            }
            $item->forceFill(['updated_by' => $actor?->id, 'version' => $creating ? 1 : (int) $item->version + 1])->save();

            $packagingIds = array_key_exists('packagings', $data) ? $this->packagings($item, (array) $data['packagings']) : null;
            if (array_key_exists('barcodes', $data)) {
                $this->barcodes($item, (array) $data['barcodes'], $packagingIds);
            }
            if (array_key_exists('uom_conversions', $data)) {
                $this->conversions($item, (array) $data['uom_conversions']);
            }
            if (array_key_exists('kit_components', $data)) {
                $this->kit($item, (array) $data['kit_components']);
            }
            if (array_key_exists('tag_ids', $data)) {
                DB::table('item_tags')->where('item_id', $item->id)->delete();
                foreach (array_unique((array) $data['tag_ids']) as $tagId) {
                    DB::table('item_tags')->insert(['id' => (string) Str::uuid7(), 'item_id' => $item->id, 'tag_id' => $tagId]);
                }
            }
            if (array_key_exists('attribute_lines', $data)) {
                $this->variants($item, (array) $data['attribute_lines']);
            }

            $this->audit->record($actor, $creating ? 'catalog.item.created' : 'catalog.item.updated', 'items', $item->id, $before,
                $item->only(['sku', 'name', 'category_id', 'status', 'tracking_mode']));
            $this->outbox->publish($creating ? 'catalog.item.created' : 'catalog.item.updated', 'items', $item->id, ['sku' => $item->sku]);

            return $item->refresh();
        });
    }

    /** Lifecycle: activate / deactivate / archive (archive requires no stock on hand). */
    public function setStatus(string $id, ItemStatus $status, ?UserAccount $actor): Item
    {
        return DB::transaction(function () use ($id, $status, $actor) {
            $item = Item::query()->lockForUpdate()->findOrFail($id);
            if ($status === ItemStatus::ARCHIVED) {
                $onHand = (string) DB::table('inventory_balances')->where('item_id', $item->id)->sum('quantity_on_hand');
                if (! D::isZero($onHand)) {
                    throw DomainException::of(ErrorCode::POLICY_VIOLATION, "{$item->label()} still has ".D::trim($onHand).' on hand; it cannot be archived.');
                }
            }
            $before = $item->status->value;
            $item->forceFill(['status' => $status, 'archived_at' => $status === ItemStatus::ARCHIVED ? now() : null, 'updated_by' => $actor?->id,
                'version' => (int) $item->version + 1])->save();
            $this->audit->record($actor, 'catalog.item.status_changed', 'items', $item->id, ['status' => $before], ['status' => $status->value]);

            return $item;
        });
    }

    private function generateSku(ItemCategory $category): string
    {
        if (! $this->config->bool('catalog.sku.generate_on_create')) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Enter a SKU.', ['field' => 'sku']);
        }
        $pattern = (string) $this->config->get('catalog.sku.pattern', ['category' => $category->id]);
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['sku:'.$category->id]);
        $n = Item::query()->where('category_id', $category->id)->count();
        for ($i = 1; $i < 1000; $i++) {
            $sku = preg_replace_callback('/\{(CAT|YYYY|YY|SEQ:(\d+))\}/', fn ($m) => match (true) {
                $m[1] === 'CAT' => preg_replace('/[^A-Za-z0-9]/', '', $category->code),
                $m[1] === 'YYYY' => now()->format('Y'), $m[1] === 'YY' => now()->format('y'),
                default => str_pad((string) ($n + $i), (int) $m[2], '0', STR_PAD_LEFT),
            }, $pattern);
            if (! Item::query()->where('sku', $sku)->exists()) {
                return mb_substr($sku, 0, 64);
            }
        }
        throw DomainException::of(ErrorCode::CONSTRAINT_VIOLATION, 'Could not generate a unique SKU; enter one.', ['field' => 'sku']);
    }

    /** @return array<int, string> packaging ids by payload index */
    private function packagings(Item $item, array $rows): array
    {
        $keep = [];
        $byIndex = [];
        foreach (array_values($rows) as $i => $p) {
            $attrs = array_intersect_key($p, array_flip(['name', 'contained_qty', 'packaging_type_lookup_value_id', 'weight', 'length', 'width', 'height',
                'is_default_purchase', 'is_default_sales', 'is_default_transfer'])) + ['updated_at' => now()];
            if (! empty($p['id']) && DB::table('item_packagings')->where('id', $p['id'])->where('item_id', $item->id)->exists()) {
                DB::table('item_packagings')->where('id', $p['id'])->update($attrs);
                $pid = $p['id'];
            } else {
                $pid = (string) Str::uuid7();
                DB::table('item_packagings')->insert($attrs + ['id' => $pid, 'item_id' => $item->id, 'created_at' => now()]);
            }
            $keep[] = $pid;
            $byIndex[$i] = $pid;
        }
        DB::table('item_barcodes')->where('item_id', $item->id)->whereNotNull('item_packaging_id')->whereNotIn('item_packaging_id', $keep ?: ['00000000-0000-0000-0000-000000000000'])->delete();
        DB::table('item_packagings')->where('item_id', $item->id)->whereNotIn('id', $keep ?: ['00000000-0000-0000-0000-000000000000'])->delete();

        return $byIndex;
    }

    /** @param  array<int, string>|null  $packagingIds */
    private function barcodes(Item $item, array $rows, ?array $packagingIds): void
    {
        $seen = [];
        foreach ($rows as $i => $b) {
            $code = (string) $b['barcode'];
            if (isset($seen[$code])) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, "Barcode $code is listed twice.", ['field' => "barcodes.$i.barcode"]);
            }
            $seen[$code] = true;
            if (DB::table('item_barcodes')->where('barcode', $code)->where('item_id', '<>', $item->id)->exists()) {
                throw DomainException::of(ErrorCode::DUPLICATE_RESOURCE, "Barcode $code belongs to another item.", ['field' => "barcodes.$i.barcode"]);
            }
        }
        DB::table('item_barcodes')->where('item_id', $item->id)->delete();
        $primarySet = false;
        foreach (array_values($rows) as $i => $b) {
            $primary = ! $primarySet && (! empty($b['is_primary']) || $i === count($rows) - 1 && ! collect($rows)->contains(fn ($r) => ! empty($r['is_primary'])));
            $primarySet = $primarySet || $primary;
            DB::table('item_barcodes')->insert(['id' => (string) Str::uuid7(), 'item_id' => $item->id, 'item_variant_id' => $b['item_variant_id'] ?? null,
                'item_packaging_id' => isset($b['packaging_index']) ? ($packagingIds[(int) $b['packaging_index']] ?? null) : null,
                'barcode' => $b['barcode'], 'symbology' => $b['symbology'] ?? (string) $this->config->get('catalog.barcode.default_symbology'),
                'is_primary' => $primary, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function conversions(Item $item, array $rows): void
    {
        DB::table('item_uom_conversions')->where('item_id', $item->id)->delete();
        foreach ($rows as $i => $c) {
            if ($c['uom_id'] === $item->base_uom_id) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'The base unit needs no conversion.', ['field' => "uom_conversions.$i.uom_id"]);
            }
            DB::table('item_uom_conversions')->insert(['id' => (string) Str::uuid7(), 'item_id' => $item->id, 'uom_id' => $c['uom_id'],
                'conversion_factor' => $c['conversion_factor'], 'rounding_precision' => $c['rounding_precision'] ?? 6, 'is_active' => $c['is_active'] ?? true,
                'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function kit(Item $item, array $rows): void
    {
        if ($rows !== [] && $item->item_type !== ItemType::KIT) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Only kit items have components.', ['field' => 'kit_components']);
        }
        DB::table('item_kit_components')->where('kit_item_id', $item->id)->delete();
        foreach ($rows as $i => $c) {
            if ($c['component_item_id'] === $item->id) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'A kit cannot contain itself.', ['field' => "kit_components.$i.component_item_id"]);
            }
            DB::table('item_kit_components')->insert(['id' => (string) Str::uuid7(), 'kit_item_id' => $item->id, 'component_item_id' => $c['component_item_id'],
                'component_variant_id' => $c['component_variant_id'] ?? null, 'quantity' => $c['quantity'], 'uom_id' => $c['uom_id'],
                'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** Attribute lines → variants (cartesian product of values of attributes that create variants). */
    private function variants(Item $item, array $lines): void
    {
        if (! $this->config->bool('catalog.variants.enabled')) {
            throw DomainException::of(ErrorCode::FEATURE_DISABLED, 'Variants are disabled.', ['field' => 'attribute_lines']);
        }
        DB::table('item_attribute_lines')->where('item_id', $item->id)->delete();
        $axes = [];
        foreach ($lines as $i => $line) {
            $attr = DB::table('item_attributes')->where('id', $line['item_attribute_id'])->first() ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED,
                'Unknown attribute.', ['field' => "attribute_lines.$i.item_attribute_id"]);
            $values = DB::table('item_attribute_values')->where('item_attribute_id', $attr->id)->whereIn('id', (array) $line['value_ids'])
                ->orderBy('sort_order')->get(['id', 'code', 'name']);
            if ($values->count() !== count(array_unique((array) $line['value_ids']))) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, "Some values do not belong to {$attr->name}.", ['field' => "attribute_lines.$i.value_ids"]);
            }
            DB::table('item_attribute_lines')->insert(['id' => (string) Str::uuid7(), 'item_id' => $item->id, 'item_attribute_id' => $attr->id,
                'value_ids' => '{'.implode(',', $values->pluck('id')->all()).'}', 'created_at' => now(), 'updated_at' => now()]);
            if ($attr->create_variant) {
                $axes[] = ['attribute' => $attr, 'values' => $values->all()];
            }
        }
        $combinations = [[]];
        foreach ($axes as $axis) {
            $next = [];
            foreach ($combinations as $combo) {
                foreach ($axis['values'] as $v) {
                    $next[] = $combo + [$axis['attribute']->code => $v];
                }
            }
            $combinations = $next;
        }
        if (count($combinations) > 500) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'This would create more than 500 variants.', ['field' => 'attribute_lines']);
        }
        $keep = [];
        foreach ($axes === [] ? [] : $combinations as $combo) {
            ksort($combo);
            $hash = hash('sha256', json_encode(array_map(fn ($v) => $v->id, $combo)));
            $keep[] = $hash;
            if (DB::table('item_variants')->where('item_id', $item->id)->where('combination_hash', $hash)->exists()) {
                DB::table('item_variants')->where('item_id', $item->id)->where('combination_hash', $hash)->where('status', ItemStatus::ARCHIVED->value)
                    ->update(['status' => ItemStatus::ACTIVE->value, 'updated_at' => now()]);

                continue;
            }
            DB::table('item_variants')->insert(['id' => (string) Str::uuid7(), 'item_id' => $item->id, 'combination_hash' => $hash,
                'attribute_values' => json_encode(array_map(fn ($v) => ['value_id' => $v->id, 'code' => $v->code, 'name' => $v->name], $combo)),
                'sku' => mb_substr($item->sku.'-'.implode('-', array_map(fn ($v) => strtoupper($v->code), $combo)), 0, 80),
                'name_suffix' => implode(' / ', array_map(fn ($v) => $v->name, $combo)), 'status' => ItemStatus::ACTIVE->value,
                'created_at' => now(), 'updated_at' => now()]);
        }
        // Variants no longer offered are archived (never deleted: they may have history).
        DB::table('item_variants')->where('item_id', $item->id)->when($keep !== [], fn ($q) => $q->whereNotIn('combination_hash', $keep))
            ->update(['status' => ItemStatus::ARCHIVED->value, 'updated_at' => now()]);
        $item->forceFill(['has_variants' => $keep !== []])->save();
    }
}
