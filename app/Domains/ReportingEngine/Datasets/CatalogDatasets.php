<?php

declare(strict_types=1);

namespace App\Domains\ReportingEngine\Datasets;

use App\Domains\Catalog\Enums\AbcClass;
use App\Domains\Catalog\Enums\CategoryStatus;
use App\Domains\Catalog\Enums\CostingMethod;
use App\Domains\Catalog\Enums\ItemStatus;
use App\Domains\Catalog\Enums\ItemType;
use App\Domains\Catalog\Enums\TrackingMode;
use App\Domains\Catalog\Enums\UomStatus;
use App\Domains\Organization\Enums\BranchKind;
use App\Domains\Organization\Enums\BranchStatus;
use App\Domains\Organization\Enums\StorageLocationLevel;
use App\Domains\Organization\Enums\StorageLocationStatus;
use App\Domains\Organization\Enums\StorageLocationUsage;
use App\Domains\Organization\Enums\WarehouseKind;
use App\Domains\Organization\Enums\WarehouseStatus;
use App\Domains\Partners\Enums\PartnerStatus;
use App\Domains\ReportingEngine\Catalog\DatasetDefinition as D;
use App\Domains\ReportingEngine\Catalog\DatasetField as F;
use App\Domains\ReportingEngine\Catalog\DatasetRelationship as R;
use App\Domains\ReportingEngine\Catalog\ProvidesDatasets;

/** Master data: items, categories, variants, units, organization, partners. */
final class CatalogDatasets implements ProvidesDatasets
{
    public static function datasets(): array
    {
        return [
            new D('items', 'Items', 'catalog', <<<'SQL'
                SELECT i.id, i.sku, i.name, i.item_type, i.status, i.category_id, c.name AS category_name, c.full_path AS category_path,
                       i.tracking_mode, i.abc_class, i.costing_method, u.code AS base_uom, i.is_sellable, i.is_purchasable, i.is_transferable,
                       i.is_critical, i.is_perishable, i.is_hazardous, i.shelf_life_days, i.standard_cost, i.current_average_cost, i.created_at
                  FROM items i JOIN item_categories c ON c.id = i.category_id JOIN units_of_measure u ON u.id = i.base_uom_id
                SQL, [
                F::uuid('id', 'Item ID', 'catalog.items'), F::string('sku', 'SKU', 'catalog.items'), F::string('name', 'Item name'),
                F::enum('item_type', 'Item type', ItemType::class), F::enum('status', 'Status', ItemStatus::class), F::uuid('category_id', 'Category ID'),
                F::string('category_name', 'Category'), F::string('category_path', 'Category path'), F::enum('tracking_mode', 'Tracking', TrackingMode::class),
                F::enum('abc_class', 'ABC class', AbcClass::class), F::enum('costing_method', 'Costing method', CostingMethod::class), F::string('base_uom', 'Base unit'),
                F::bool('is_sellable', 'Sellable'), F::bool('is_purchasable', 'Purchasable'), F::bool('is_transferable', 'Transferable'), F::bool('is_critical', 'Critical'),
                F::bool('is_perishable', 'Perishable'), F::bool('is_hazardous', 'Hazardous'), F::int('shelf_life_days', 'Shelf life (days)'),
                F::money('standard_cost', 'Standard cost'), F::money('current_average_cost', 'Average cost'), F::datetime('created_at', 'Created'),
            ], 'catalog.item.read', relationships: [R::to('category', 'Category', 'item_categories', 'category_id')], defaultSort: [['sku', 'asc']]),

            new D('item_categories', 'Item categories', 'catalog', <<<'SQL'
                SELECT c.id, c.code, c.name, c.full_path, c.code_path, c.depth, c.parent_id, c.status, c.default_tracking_mode, c.is_hazardous,
                       (SELECT count(*) FROM items i WHERE i.category_id = c.id) AS item_count
                  FROM item_categories c
                SQL, [
                F::uuid('id', 'Category ID'), F::string('code', 'Code'), F::string('name', 'Name'), F::string('full_path', 'Path'), F::string('code_path', 'Code path'),
                F::int('depth', 'Depth'), F::uuid('parent_id', 'Parent ID'), F::enum('status', 'Status', CategoryStatus::class),
                F::enum('default_tracking_mode', 'Default tracking', TrackingMode::class), F::bool('is_hazardous', 'Hazardous'), F::int('item_count', 'Items'),
            ], 'catalog.category.read', relationships: [R::to('parent', 'Parent category', 'item_categories', 'parent_id')], defaultSort: [['full_path', 'asc']]),

            new D('item_variants', 'Item variants', 'catalog', <<<'SQL'
                SELECT v.id, v.item_id, i.sku AS item_sku, i.name AS item_name, v.sku, v.name_suffix, v.status, v.extra_list_price, v.created_at
                  FROM item_variants v JOIN items i ON i.id = v.item_id
                SQL, [
                F::uuid('id', 'Variant ID'), F::uuid('item_id', 'Item ID'), F::string('item_sku', 'Item SKU'), F::string('item_name', 'Item name'),
                F::string('sku', 'Variant SKU'), F::string('name_suffix', 'Variant'), F::enum('status', 'Status', ItemStatus::class),
                F::money('extra_list_price', 'Extra list price', \App\Support\Enums\DataClassification::OPERATIONAL), F::datetime('created_at', 'Created'),
            ], 'catalog.variant.read', relationships: [R::to('item', 'Item', 'items', 'item_id')]),

            new D('units_of_measure', 'Units of measure', 'catalog', <<<'SQL'
                SELECT u.id, u.code, u.name, u.symbol, u.ratio_to_reference, u.is_reference, u.is_whole_number, u.status FROM units_of_measure u
                SQL, [
                F::uuid('id', 'Unit ID'), F::string('code', 'Code'), F::string('name', 'Name'), F::string('symbol', 'Symbol'),
                F::decimal('ratio_to_reference', 'Ratio to reference'), F::bool('is_reference', 'Reference unit'), F::bool('is_whole_number', 'Whole numbers only'),
                F::enum('status', 'Status', UomStatus::class),
            ], 'catalog.uom.read', defaultSort: [['code', 'asc']]),

            new D('branches', 'Branches', 'organization', <<<'SQL'
                SELECT b.id, b.id AS branch_id, b.code, b.name, b.kind, b.status, b.timezone, p.code AS parent_code, b.opened_on, b.closed_on
                  FROM branches b LEFT JOIN branches p ON p.id = b.parent_branch_id
                SQL, [
                F::uuid('id', 'Branch ID'), F::uuid('branch_id', 'Branch scope'), F::string('code', 'Code'), F::string('name', 'Name'), F::enum('kind', 'Kind', BranchKind::class),
                F::enum('status', 'Status', BranchStatus::class), F::string('timezone', 'Timezone'), F::string('parent_code', 'Parent branch'),
                F::date('opened_on', 'Opened'), F::date('closed_on', 'Closed'),
            ], 'organization.branch.read', branchScope: 'branch_id', defaultSort: [['code', 'asc']]),

            new D('warehouses', 'Warehouses', 'organization', <<<'SQL'
                SELECT w.id, w.id AS warehouse_id, w.code, w.name, w.kind, w.status, w.branch_id, b.code AS branch_code, b.name AS branch_name,
                       array_to_string(w.capabilities, ', ') AS capabilities
                  FROM warehouses w JOIN branches b ON b.id = w.branch_id
                SQL, [
                F::uuid('id', 'Warehouse ID'), F::uuid('warehouse_id', 'Warehouse scope'), F::string('code', 'Code'), F::string('name', 'Name'),
                F::enum('kind', 'Kind', WarehouseKind::class), F::enum('status', 'Status', WarehouseStatus::class), F::uuid('branch_id', 'Branch ID'),
                F::string('branch_code', 'Branch'), F::string('branch_name', 'Branch name'), F::string('capabilities', 'Capabilities'),
            ], 'organization.warehouse.read', warehouseScope: ['warehouse_id'], branchScope: 'branch_id',
                relationships: [R::to('branch', 'Branch', 'branches', 'branch_id')], defaultSort: [['code', 'asc']]),

            new D('storage_locations', 'Storage locations', 'organization', <<<'SQL'
                SELECT sl.id, sl.warehouse_id, w.code AS warehouse_code, w.branch_id, sl.code, sl.full_path, sl.name, sl.level, sl.usage, sl.status,
                       sl.is_pickable, sl.is_putaway_target, sl.is_system, sl.sort_order, sl.last_counted_at
                  FROM storage_locations sl JOIN warehouses w ON w.id = sl.warehouse_id
                SQL, [
                F::uuid('id', 'Location ID'), F::uuid('warehouse_id', 'Warehouse ID'), F::string('warehouse_code', 'Warehouse'), F::uuid('branch_id', 'Branch ID'),
                F::string('code', 'Code'), F::string('full_path', 'Path'), F::string('name', 'Name'), F::enum('level', 'Level', StorageLocationLevel::class),
                F::enum('usage', 'Usage', StorageLocationUsage::class), F::enum('status', 'Status', StorageLocationStatus::class), F::bool('is_pickable', 'Pickable'),
                F::bool('is_putaway_target', 'Put-away target'), F::bool('is_system', 'System location'), F::int('sort_order', 'Path order'),
                F::datetime('last_counted_at', 'Last counted'),
            ], 'organization.storage_location.read', warehouseScope: ['warehouse_id'], branchScope: 'branch_id',
                relationships: [R::to('warehouse', 'Warehouse', 'warehouses', 'warehouse_id')], defaultSort: [['full_path', 'asc']]),

            new D('partners', 'Partners (suppliers, customers, carriers)', 'partners', <<<'SQL'
                SELECT p.id, p.code, p.name, array_to_string(p.partner_types, ', ') AS partner_types, 'supplier' = ANY (p.partner_types) AS is_supplier,
                       'customer' = ANY (p.partner_types) AS is_customer, 'carrier' = ANY (p.partner_types) AS is_carrier, p.status, p.default_currency,
                       p.lead_time_days, p.rating, p.is_internal, p.email, p.phone, p.created_at
                  FROM partners p
                SQL, [
                F::uuid('id', 'Partner ID'), F::string('code', 'Code'), F::string('name', 'Name'), F::string('partner_types', 'Types'),
                F::bool('is_supplier', 'Supplier'), F::bool('is_customer', 'Customer'), F::bool('is_carrier', 'Carrier'), F::enum('status', 'Status', PartnerStatus::class),
                F::string('default_currency', 'Currency'), F::int('lead_time_days', 'Lead time (days)'), F::int('rating', 'Rating (1–5)'), F::bool('is_internal', 'Internal'),
                F::personal('email', 'Email'), F::personal('phone', 'Phone'), F::datetime('created_at', 'Created'),
            ], 'partners.partner.read', defaultSort: [['code', 'asc']]),
        ];
    }
}
