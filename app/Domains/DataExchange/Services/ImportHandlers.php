<?php

declare(strict_types=1);

namespace App\Domains\DataExchange\Services;

use App\Domains\Catalog\Actions\SaveCategoryAction;
use App\Domains\Catalog\Actions\SaveItemAction;
use App\Domains\Catalog\Enums\UomStatus;
use App\Domains\Catalog\Models\Item;
use App\Domains\Catalog\Models\ItemCategory;
use App\Domains\Catalog\Models\ItemReorderRule;
use App\Domains\Catalog\Models\ItemVendorPricelist;
use App\Domains\Catalog\Models\UnitOfMeasure;
use App\Domains\Catalog\Schemas\ItemCategorySchema;
use App\Domains\Catalog\Schemas\ItemReorderRuleSchema;
use App\Domains\Catalog\Schemas\ItemSchema;
use App\Domains\Catalog\Schemas\ItemVendorPricelistSchema;
use App\Domains\Catalog\Schemas\UnitOfMeasureSchema;
use App\Domains\DataExchange\Enums\ImportType as T;
use App\Domains\IdentityAccess\Actions\SaveRoleAction;
use App\Domains\IdentityAccess\Models\Role;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\IdentityAccess\Schemas\RoleSchema;
use App\Domains\Inventory\Documents\OpeningBalanceWorkflow;
use App\Domains\Inventory\Schemas\OpeningBalanceLineSchema;
use App\Domains\Inventory\Services\LotService;
use App\Domains\Organization\Actions\CreateBranchAction;
use App\Domains\Organization\Actions\CreateStorageLocationAction;
use App\Domains\Organization\Actions\CreateWarehouseAction;
use App\Domains\Organization\Schemas\BranchSchema;
use App\Domains\Organization\Schemas\StorageLocationSchema;
use App\Domains\Organization\Schemas\WarehouseSchema;
use App\Domains\Partners\Actions\SavePartnerAction;
use App\Domains\Partners\Models\Partner;
use App\Domains\Partners\Schemas\PartnerSchema;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Resources\ResourceWriter;
use App\Support\Schema\TableSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Per-type import mapping (Chapter 11 §11.9). Spreadsheet columns use schema property names, plus
 * friendly *_code / sku columns resolved to ids; list cells are separated by ";". Each type
 * declares its schema (validation reuses the global property validator), an upsert key, and the
 * action that writes it. Imports never post stock: opening balances become draft documents.
 */
final class ImportHandlers
{
    public function __construct(private readonly ResourceWriter $writer) {}

    /**
     * @return array{schema: class-string<TableSchema>|null, columns: array<int, string>, required: array<int, string>, lists: array<int, string>}
     */
    public function spec(T $type): array
    {
        return match ($type) {
            T::ITEMS => ['schema' => ItemSchema::class, 'required' => ['name', 'category_code'], 'lists' => ['tag_ids'],
                'columns' => ['sku', 'name', 'category_code', 'item_type', 'base_uom_code', 'purchase_uom_code', 'sales_uom_code', 'transfer_uom_code', 'barcode',
                    'tracking_mode', 'expiry_policy', 'shelf_life_days', 'removal_strategy', 'quality_control_policy', 'is_sellable', 'is_purchasable',
                    'is_transferable', 'is_perishable', 'is_hazardous', 'is_critical', 'abc_class', 'model_number', 'description_short', 'hs_code',
                    'country_of_origin', 'weight', 'length', 'width', 'height', 'min_order_qty', 'order_multiple', 'list_price', 'list_price_currency',
                    'standard_cost', 'costing_method', 'counting_frequency_days', 'supplier_lead_time_days']],
            T::CATEGORIES => ['schema' => ItemCategorySchema::class, 'required' => ['code', 'name'], 'lists' => [],
                'columns' => ['code', 'name', 'parent_code', 'sort_order', 'description', 'default_tracking_mode', 'default_expiry_policy',
                    'default_removal_strategy', 'default_qc_policy', 'default_costing_method', 'default_abc_class', 'requires_approval_for_issue', 'is_hazardous']],
            T::UOM => ['schema' => UnitOfMeasureSchema::class, 'required' => ['code', 'name', 'symbol', 'uom_category_code'], 'lists' => [],
                'columns' => ['code', 'name', 'symbol', 'uom_category_code', 'ratio_to_reference', 'rounding_precision', 'is_reference', 'is_whole_number']],
            T::BARCODES => ['schema' => null, 'required' => ['sku', 'barcode'], 'lists' => [], 'columns' => ['sku', 'barcode', 'symbology', 'variant_sku', 'is_primary']],
            T::PACKAGINGS => ['schema' => null, 'required' => ['sku', 'name', 'contained_qty'], 'lists' => [],
                'columns' => ['sku', 'name', 'contained_qty', 'weight', 'length', 'width', 'height', 'is_default_purchase', 'is_default_sales', 'is_default_transfer']],
            T::VARIANTS => ['schema' => null, 'required' => ['sku', 'attributes'], 'lists' => [], 'columns' => ['sku', 'attributes']],
            T::VENDOR_PRICELISTS => ['schema' => ItemVendorPricelistSchema::class, 'required' => ['sku', 'supplier_code', 'unit_price', 'currency'], 'lists' => [],
                'columns' => ['sku', 'supplier_code', 'vendor_product_code', 'vendor_product_name', 'min_qty', 'unit_price', 'currency', 'uom_code',
                    'valid_from', 'valid_to', 'lead_time_days', 'is_preferred']],
            T::REORDER_RULES => ['schema' => ItemReorderRuleSchema::class, 'required' => ['sku'], 'lists' => [],
                'columns' => ['sku', 'warehouse_code', 'min_qty', 'max_qty', 'reorder_point', 'reorder_qty', 'safety_stock', 'multiple_of_qty',
                    'preferred_supplier_code', 'preferred_source_warehouse_code', 'replenishment_method', 'lead_time_days', 'review_period_days', 'is_active']],
            T::BRANCHES => ['schema' => BranchSchema::class, 'required' => ['code', 'name', 'kind'], 'lists' => [],
                'columns' => ['code', 'name', 'kind', 'parent_code', 'timezone', 'opened_on']],
            T::WAREHOUSES => ['schema' => WarehouseSchema::class, 'required' => ['code', 'name', 'branch_code'], 'lists' => ['capabilities'],
                'columns' => ['code', 'name', 'branch_code', 'kind', 'capabilities']],
            T::STORAGE_LOCATIONS => ['schema' => StorageLocationSchema::class, 'required' => ['warehouse_code', 'code', 'name'], 'lists' => [],
                'columns' => ['warehouse_code', 'parent_path', 'code', 'name', 'level', 'usage', 'is_pickable', 'is_putaway_target', 'barcode', 'sort_order', 'counting_frequency_days']],
            T::PARTNERS => ['schema' => PartnerSchema::class, 'required' => ['code', 'name', 'partner_types'], 'lists' => ['partner_types'],
                'columns' => ['code', 'name', 'legal_name', 'partner_types', 'tax_id', 'email', 'phone', 'website', 'default_currency', 'lead_time_days', 'rating', 'notes']],
            T::LOTS => ['schema' => null, 'required' => ['sku', 'lot_number'], 'lists' => [],
                'columns' => ['sku', 'lot_number', 'manufacture_date', 'expiry_date', 'supplier_code', 'supplier_lot_reference']],
            T::OPENING_BALANCES => ['schema' => OpeningBalanceLineSchema::class, 'required' => ['warehouse_code', 'location_path', 'sku', 'quantity', 'unit_cost', 'cutoff_date'],
                'lists' => ['serial_numbers'], 'columns' => ['warehouse_code', 'cutoff_date', 'location_path', 'sku', 'quantity', 'unit_cost', 'lot_number', 'expiry_date',
                    'manufacture_date', 'serial_numbers', 'stock_condition', 'notes']],
            T::ROLES => ['schema' => RoleSchema::class, 'required' => ['code', 'name', 'permissions'], 'lists' => ['permissions'],
                'columns' => ['code', 'name', 'description', 'permissions']],
        };
    }

    /**
     * Resolve friendly columns (codes, SKUs, paths) to ids; returns [payload, errors].
     *
     * @param  array<string, mixed>  $row
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    public function map(T $type, array $row, array $codesInFile = []): array
    {
        $errors = [];
        // A parent defined earlier in the same file is resolved when the row is imported.
        if (in_array($type, [T::CATEGORIES, T::BRANCHES], true) && isset($row['parent_code']) && in_array($row['parent_code'], $codesInFile, true)
            && ! DB::table($type === T::BRANCHES ? 'branches' : 'item_categories')->where('code', $row['parent_code'])->exists()) {
            $row['__parent_code'] = $row['parent_code'];
            unset($row['parent_code']);
        }
        $lookup = function (string $column, string $table, string $field, string $target, string $label, ?callable $q = null) use (&$row, &$errors) {
            if (! isset($row[$column]) || $row[$column] === null) {
                unset($row[$column]);

                return;
            }
            $query = DB::table($table)->where($field, $row[$column]);
            $id = ($q ? $q($query) : $query)->value('id');
            if ($id === null) {
                $errors[$column] = "Unknown $label `{$row[$column]}`.";
            } else {
                $row[$target] = $id;
            }
            unset($row[$column]);
        };
        $lookup('category_code', 'item_categories', 'code', 'category_id', 'category');
        $lookup('parent_code', $type === T::BRANCHES ? 'branches' : 'item_categories', 'code', $type === T::BRANCHES ? 'parent_branch_id' : 'parent_id', 'parent');
        foreach (['base', 'purchase', 'sales', 'transfer'] as $p) {
            $lookup("{$p}_uom_code", 'units_of_measure', 'code', "{$p}_uom_id", 'unit');
        }
        $lookup('uom_code', 'units_of_measure', 'code', 'uom_id', 'unit');
        $lookup('uom_category_code', 'uom_categories', 'code', 'uom_category_id', 'unit category');
        if ($type === T::ITEMS) {
            // An item row's SKU is its upsert key: a known SKU updates that item, an unknown one creates it.
            $existing = isset($row['sku']) ? DB::table('items')->where('sku', $row['sku'])->value('id') : null;
            if ($existing !== null) {
                $row['__existing_id'] = $existing;
            }
        } else {
            $lookup('sku', 'items', 'sku', 'item_id', 'SKU');
        }
        $lookup('supplier_code', 'partners', 'code', $type === T::VENDOR_PRICELISTS ? 'supplier_id' : 'supplier_id', 'supplier');
        $lookup('preferred_supplier_code', 'partners', 'code', 'preferred_supplier_id', 'supplier');
        $lookup('warehouse_code', 'warehouses', 'code', 'warehouse_id', 'warehouse');
        $lookup('preferred_source_warehouse_code', 'warehouses', 'code', 'preferred_source_warehouse_id', 'warehouse');
        $lookup('branch_code', 'branches', 'code', 'branch_id', 'branch');
        if (isset($row['location_path'])) {
            $path = (string) $row['location_path'];
            $wh = isset($row['warehouse_id']) ? DB::table('warehouses')->where('id', $row['warehouse_id'])->value('code') : null;
            $full = $wh !== null && ! str_starts_with($path, $wh.'/') ? $wh.'/'.$path : $path;
            $id = DB::table('storage_locations')->where('full_path', $full)->value('id');
            $id === null ? $errors['location_path'] = "Unknown location `$path`." : $row['storage_location_id'] = $id;
            unset($row['location_path']);
        }
        if (isset($row['parent_path'])) {
            $path = (string) $row['parent_path'];
            $id = DB::table('storage_locations')->where('full_path', $path)->value('id');
            $id === null ? $errors['parent_path'] = "Unknown parent location `$path`." : $row['parent_id'] = $id;
            unset($row['parent_path']);
        }
        if ($type === T::ITEMS && isset($row['barcode'])) {
            $row['barcodes'] = [['barcode' => (string) $row['barcode'], 'is_primary' => true]];
            unset($row['barcode']);
        }

        return [$row, $errors];
    }

    /** Schema used to validate the mapped payload (create vs update for upserts). */
    public function operation(T $type, array $payload): string
    {
        return $this->existingId($type, $payload) === null ? 'create' : 'update';
    }

    /** Write one valid row; returns the created/updated id. */
    public function import(T $type, array $payload, UserAccount $actor): ?string
    {
        $existing = $this->existingId($type, $payload);
        if (isset($payload['__parent_code'])) {
            $table = $type === T::BRANCHES ? 'branches' : 'item_categories';
            $payload[$type === T::BRANCHES ? 'parent_branch_id' : 'parent_id'] = DB::table($table)->where('code', $payload['__parent_code'])->value('id')
                ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED, "Parent `{$payload['__parent_code']}` was not imported.", ['field' => 'parent_code']);
        }
        unset($payload['__existing_id'], $payload['__parent_code']);

        return match ($type) {
            T::ITEMS => app(SaveItemAction::class)->execute($payload, $actor, $existing)->id,
            T::CATEGORIES => app(SaveCategoryAction::class)->execute($payload, $actor, $existing)->id,
            T::UOM => $existing === null
                ? $this->writer->create(UnitOfMeasureSchema::class, UnitOfMeasure::class, $payload, $actor, ['status' => UomStatus::ACTIVE])->id
                : $this->writer->update(UnitOfMeasureSchema::class, UnitOfMeasure::class, $existing, $payload, $actor)->id,
            T::VENDOR_PRICELISTS => $existing === null
                ? $this->writer->create(ItemVendorPricelistSchema::class, ItemVendorPricelist::class, $payload, $actor)->id
                : $this->writer->update(ItemVendorPricelistSchema::class, ItemVendorPricelist::class, $existing, $payload, $actor)->id,
            T::REORDER_RULES => $existing === null
                ? $this->writer->create(ItemReorderRuleSchema::class, ItemReorderRule::class, $payload, $actor)->id
                : $this->writer->update(ItemReorderRuleSchema::class, ItemReorderRule::class, $existing, $payload, $actor)->id,
            T::BRANCHES => $existing ?? app(CreateBranchAction::class)->execute($payload, $actor)->id,
            T::WAREHOUSES => $existing ?? app(CreateWarehouseAction::class)->execute($payload, $actor)->id,
            T::STORAGE_LOCATIONS => $existing ?? app(CreateStorageLocationAction::class)->execute($payload, $actor)->id,
            T::PARTNERS => app(SavePartnerAction::class)->execute($payload, $actor, $existing ? Partner::query()->find($existing) : null)->id,
            T::ROLES => app(SaveRoleAction::class)->execute($payload, $actor, $existing ? Role::query()->find($existing) : null)->id,
            T::BARCODES => $this->barcode($payload),
            T::PACKAGINGS => $this->packaging($payload),
            T::VARIANTS => $this->variant($payload, $actor),
            T::LOTS => $this->lot($payload, $actor),
            T::OPENING_BALANCES => throw new \LogicException('Opening balances are imported per document (see ImportService).'),
        };
    }

    /**
     * Opening balances: one draft document per warehouse and cut-off date (never posted by an import).
     *
     * @param  array<int, array<string, mixed>>  $rows  mapped, valid payloads
     * @return array<int, string> document ids
     */
    public function openingBalances(array $rows, UserAccount $actor): array
    {
        $ids = [];
        foreach (collect($rows)->groupBy(fn ($r) => $r['warehouse_id'].'|'.$r['cutoff_date']) as $group) {
            $first = $group->first();
            $doc = app(OpeningBalanceWorkflow::class)->save(['warehouse_id' => $first['warehouse_id'], 'cutoff_date' => $first['cutoff_date'],
                'reference' => 'Import', 'lines' => $group->map(fn ($r) => array_diff_key($r, array_flip(['warehouse_id', 'cutoff_date'])))->values()->all()], $actor);
            $ids[] = $doc->id;
        }

        return $ids;
    }

    private function existingId(T $type, array $payload): ?string
    {
        return match ($type) {
            T::ITEMS => $payload['__existing_id'] ?? (isset($payload['sku']) ? Item::query()->where('sku', $payload['sku'])->value('id') : null),
            T::CATEGORIES => ItemCategory::query()->where('code', $payload['code'] ?? null)->value('id'),
            T::UOM => UnitOfMeasure::query()->where('code', $payload['code'] ?? null)->value('id'),
            T::VENDOR_PRICELISTS => DB::table('item_vendor_pricelists')->where('item_id', $payload['item_id'] ?? null)
                ->where('supplier_id', $payload['supplier_id'] ?? null)->where('min_qty', $payload['min_qty'] ?? 0)->value('id'),
            T::REORDER_RULES => DB::table('item_reorder_rules')->where('item_id', $payload['item_id'] ?? null)
                ->where(fn ($q) => isset($payload['warehouse_id']) ? $q->where('warehouse_id', $payload['warehouse_id']) : $q->whereNull('warehouse_id'))->value('id'),
            T::BRANCHES => DB::table('branches')->where('code', $payload['code'] ?? null)->value('id'),
            T::WAREHOUSES => DB::table('warehouses')->where('code', $payload['code'] ?? null)->value('id'),
            T::STORAGE_LOCATIONS => DB::table('storage_locations')->where('warehouse_id', $payload['warehouse_id'] ?? null)->where('code', $payload['code'] ?? null)
                ->where(fn ($q) => isset($payload['parent_id']) ? $q->where('parent_id', $payload['parent_id']) : $q->whereNull('parent_id'))->value('id'),
            T::PARTNERS => Partner::query()->where('code', $payload['code'] ?? null)->value('id'),
            T::ROLES => Role::query()->where('code', $payload['code'] ?? null)->value('id'),
            default => null,
        };
    }

    private function barcode(array $p): string
    {
        $owner = DB::table('item_barcodes')->where('barcode', $p['barcode'])->value('item_id');
        if ($owner !== null && $owner !== $p['item_id']) {
            throw DomainException::of(ErrorCode::DUPLICATE_RESOURCE, "Barcode {$p['barcode']} belongs to another item.", ['field' => 'barcode']);
        }
        $variantId = isset($p['variant_sku']) ? DB::table('item_variants')->where('sku', $p['variant_sku'])->where('item_id', $p['item_id'])->value('id') : null;
        if (! empty($p['is_primary'])) {
            DB::table('item_barcodes')->where('item_id', $p['item_id'])->update(['is_primary' => false]);
        }
        $id = DB::table('item_barcodes')->where('barcode', $p['barcode'])->value('id') ?? (string) Str::uuid7();
        DB::table('item_barcodes')->updateOrInsert(['barcode' => $p['barcode']], ['id' => $id, 'item_id' => $p['item_id'], 'item_variant_id' => $variantId,
            'symbology' => $p['symbology'] ?? 'code128', 'is_primary' => (bool) ($p['is_primary'] ?? false), 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function packaging(array $p): string
    {
        $id = DB::table('item_packagings')->where('item_id', $p['item_id'])->where('name', $p['name'])->value('id') ?? (string) Str::uuid7();
        DB::table('item_packagings')->updateOrInsert(['id' => $id], array_intersect_key($p, array_flip(['item_id', 'name', 'contained_qty', 'weight', 'length',
            'width', 'height', 'is_default_purchase', 'is_default_sales', 'is_default_transfer'])) + ['created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    /** `attributes` = "color=red;size=m": merges the values into the item's attribute lines (variants are regenerated). */
    private function variant(array $p, UserAccount $actor): string
    {
        $lines = DB::table('item_attribute_lines')->where('item_id', $p['item_id'])->get()
            ->mapWithKeys(fn ($l) => [$l->item_attribute_id => (array) json_decode((string) $l->value_ids, true)])->all();
        foreach (array_filter(explode(';', (string) $p['attributes'])) as $pair) {
            [$attrCode, $valueCode] = array_map('trim', explode('=', $pair, 2) + [1 => '']);
            $attr = DB::table('item_attributes')->where('code', $attrCode)->first() ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED, "Unknown attribute `$attrCode`.", ['field' => 'attributes']);
            $value = DB::table('item_attribute_values')->where('item_attribute_id', $attr->id)->where('code', $valueCode)->value('id')
                ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED, "Unknown value `$valueCode` for $attrCode.", ['field' => 'attributes']);
            $lines[$attr->id] = array_values(array_unique(array_merge($lines[$attr->id] ?? [], [$value])));
        }
        $item = app(SaveItemAction::class)->execute(['attribute_lines' => array_map(fn ($a, $v) => ['item_attribute_id' => $a, 'value_ids' => $v], array_keys($lines), $lines)],
            $actor, $p['item_id']);

        return $item->id;
    }

    private function lot(array $p, UserAccount $actor): string
    {
        $item = Item::query()->findOrFail($p['item_id']);

        return app(LotService::class)->resolveForReceipt($item, null, $p['lot_number'], $p['supplier_id'] ?? null, $p['manufacture_date'] ?? null,
            $p['expiry_date'] ?? null, 'import', (string) Str::uuid7(), $actor->id, array_filter(['supplier_lot_reference' => $p['supplier_lot_reference'] ?? null]))->id;
    }
}
