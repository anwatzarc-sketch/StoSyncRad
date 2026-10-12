<?php

declare(strict_types=1);

namespace App\Domains\ReportingEngine\Datasets;

use App\Domains\Catalog\Enums\CostingMethod;
use App\Domains\Inventory\Enums\LotLockStatus;
use App\Domains\Inventory\Enums\LotQcStatus;
use App\Domains\Inventory\Enums\LotStatus;
use App\Domains\Inventory\Enums\MovementStatus;
use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Enums\ReservationReferenceType;
use App\Domains\Inventory\Enums\ReservationStatus;
use App\Domains\Inventory\Enums\SerialStatus;
use App\Domains\Inventory\Enums\StockCondition;
use App\Domains\Inventory\Enums\ValuationLayerStatus;
use App\Domains\Organization\Enums\DocumentType;
use App\Domains\Organization\Enums\InventoryLocationType;
use App\Domains\ReportingEngine\Catalog\DatasetDefinition as D;
use App\Domains\ReportingEngine\Catalog\DatasetField as F;
use App\Domains\ReportingEngine\Catalog\DatasetRelationship as R;
use App\Domains\ReportingEngine\Catalog\ProvidesDatasets;
use App\Support\Enums\DataClassification as C;

/** Stock positions, ledger, lots, serials, reservations, valuation and traceability (Chapter 07). */
final class InventoryDatasets implements ProvidesDatasets
{
    private const S = "'00000000-0000-7000-8000-000000000000'::uuid";

    public static function datasets(): array
    {
        $s = self::S;

        return [
            new D('stock_positions', 'Stock positions (current balances)', 'inventory', <<<SQL
                SELECT b.id, b.item_id, i.sku AS item_sku, i.name AS item_name, i.category_id, c.full_path AS category_path, i.abc_class,
                       NULLIF(b.item_variant_id, $s) AS item_variant_id, b.warehouse_id, w.code AS warehouse_code, w.branch_id,
                       il.storage_location_id, sl.full_path AS location_path, il.location_type, NULLIF(b.inventory_lot_id, $s) AS inventory_lot_id,
                       l.lot_number, l.expiry_date, b.stock_condition, b.quantity_on_hand, b.quantity_reserved,
                       (b.quantity_on_hand - b.quantity_reserved) AS quantity_available, u.code AS base_uom,
                       CASE WHEN b.quantity_on_hand <> 0 THEN round(b.total_value_projection / b.quantity_on_hand, 8) END AS unit_cost,
                       b.total_value_projection AS total_value, b.is_frozen, b.last_movement_at, b.last_counted_at
                  FROM inventory_balances b
                  JOIN items i ON i.id = b.item_id JOIN item_categories c ON c.id = i.category_id JOIN units_of_measure u ON u.id = i.base_uom_id
                  JOIN inventory_locations il ON il.id = b.inventory_location_id
                  LEFT JOIN storage_locations sl ON sl.id = il.storage_location_id LEFT JOIN warehouses w ON w.id = b.warehouse_id
                  LEFT JOIN inventory_lots l ON l.id = b.inventory_lot_id
                 WHERE b.quantity_on_hand <> 0 OR b.quantity_reserved <> 0
                SQL, [
                F::uuid('id', 'Position ID'), F::uuid('item_id', 'Item ID', 'catalog.items'), F::string('item_sku', 'SKU', 'catalog.items'), F::string('item_name', 'Item'),
                F::uuid('category_id', 'Category ID'), F::string('category_path', 'Category'), F::enum('abc_class', 'ABC class', \App\Domains\Catalog\Enums\AbcClass::class),
                F::uuid('item_variant_id', 'Variant ID'), F::uuid('warehouse_id', 'Warehouse ID'), F::string('warehouse_code', 'Warehouse'), F::uuid('branch_id', 'Branch ID'),
                F::uuid('storage_location_id', 'Location ID'), F::string('location_path', 'Location'), F::enum('location_type', 'Location type', InventoryLocationType::class),
                F::uuid('inventory_lot_id', 'Lot ID'), F::string('lot_number', 'Lot'), F::date('expiry_date', 'Expiry'), F::enum('stock_condition', 'Condition', StockCondition::class),
                F::qty('quantity_on_hand', 'On hand'), F::qty('quantity_reserved', 'Reserved'), F::qty('quantity_available', 'Available'), F::string('base_uom', 'Unit'),
                F::money('unit_cost', 'Unit cost'), F::money('total_value', 'Value'), F::bool('is_frozen', 'Frozen by count'),
                F::datetime('last_movement_at', 'Last movement'), F::datetime('last_counted_at', 'Last counted'),
            ], 'inventory.stock.read', warehouseScope: ['warehouse_id'], branchScope: 'branch_id',
                relationships: [R::to('item', 'Item', 'items', 'item_id'), R::to('warehouse', 'Warehouse', 'warehouses', 'warehouse_id'),
                    R::to('lot', 'Lot', 'lots', 'inventory_lot_id')], defaultSort: [['item_sku', 'asc'], ['location_path', 'asc']]),

            new D('reservations', 'Stock reservations', 'inventory', <<<SQL
                SELECT r.id, r.reference_type, r.reference_id, r.reference_line_id, r.item_id, i.sku AS item_sku, i.name AS item_name, r.warehouse_id,
                       w.code AS warehouse_code, w.branch_id, sl.full_path AS location_path, l.lot_number, r.quantity_reserved, r.quantity_consumed,
                       r.quantity_released, (r.quantity_reserved - r.quantity_consumed - r.quantity_released) AS quantity_outstanding, r.status,
                       r.expires_at, r.created_at
                  FROM stock_reservations r JOIN items i ON i.id = r.item_id JOIN inventory_locations il ON il.id = r.inventory_location_id
                  LEFT JOIN storage_locations sl ON sl.id = il.storage_location_id LEFT JOIN warehouses w ON w.id = r.warehouse_id
                  LEFT JOIN inventory_lots l ON l.id = NULLIF(r.inventory_lot_id, $s)
                SQL, [
                F::uuid('id', 'Reservation ID'), F::enum('reference_type', 'For', ReservationReferenceType::class), F::uuid('reference_id', 'Document ID'),
                F::uuid('reference_line_id', 'Document line ID'), F::uuid('item_id', 'Item ID'), F::string('item_sku', 'SKU'), F::string('item_name', 'Item'),
                F::uuid('warehouse_id', 'Warehouse ID'), F::string('warehouse_code', 'Warehouse'), F::uuid('branch_id', 'Branch ID'), F::string('location_path', 'Location'),
                F::string('lot_number', 'Lot'), F::qty('quantity_reserved', 'Reserved'), F::qty('quantity_consumed', 'Consumed'), F::qty('quantity_released', 'Released'),
                F::qty('quantity_outstanding', 'Outstanding'), F::enum('status', 'Status', ReservationStatus::class), F::datetime('expires_at', 'Expires'),
                F::datetime('created_at', 'Created'),
            ], 'inventory.reservation.read', warehouseScope: ['warehouse_id'], branchScope: 'branch_id',
                relationships: [R::to('item', 'Item', 'items', 'item_id')], defaultSort: [['created_at', 'desc']]),

            new D('stock_movements', 'Stock movements (ledger headers)', 'inventory', <<<'SQL'
                SELECT m.id, m.movement_number, m.movement_type, m.status, m.source_document_type, m.source_document_id, m.source_document_number,
                       m.posted_at, m.business_date, m.warehouse_id, w.code AS warehouse_code, w.branch_id, m.counterparty_warehouse_id,
                       cw.code AS counterparty_warehouse_code, m.partner_id, p.name AS partner_name, m.reversal_of_movement_id, m.reason_text,
                       ua.username AS posted_by, (SELECT count(*) FROM stock_movement_lines x WHERE x.stock_movement_id = m.id) AS line_count
                  FROM stock_movements m LEFT JOIN warehouses w ON w.id = m.warehouse_id LEFT JOIN warehouses cw ON cw.id = m.counterparty_warehouse_id
                  LEFT JOIN partners p ON p.id = m.partner_id LEFT JOIN user_accounts ua ON ua.id = m.posting_actor_id
                SQL, [
                F::uuid('id', 'Movement ID'), F::string('movement_number', 'Movement'), F::enum('movement_type', 'Type', MovementType::class),
                F::enum('status', 'Status', MovementStatus::class), F::enum('source_document_type', 'Document type', DocumentType::class),
                F::uuid('source_document_id', 'Document ID'), F::string('source_document_number', 'Document'), F::datetime('posted_at', 'Posted at'),
                F::date('business_date', 'Business date'), F::uuid('warehouse_id', 'Warehouse ID'), F::string('warehouse_code', 'Warehouse'), F::uuid('branch_id', 'Branch ID'),
                F::uuid('counterparty_warehouse_id', 'Counterparty warehouse ID'), F::string('counterparty_warehouse_code', 'Counterparty warehouse'),
                F::uuid('partner_id', 'Partner ID'), F::string('partner_name', 'Partner'), F::uuid('reversal_of_movement_id', 'Reverses'), F::string('reason_text', 'Reason'),
                F::personal('posted_by', 'Posted by'), F::int('line_count', 'Lines'),
            ], 'inventory.movement.read', warehouseScope: ['warehouse_id', 'counterparty_warehouse_id'], branchScope: 'branch_id',
                relationships: [R::many('lines', 'Lines', 'stock_movement_lines', 'stock_movement_id')], defaultSort: [['posted_at', 'desc']]),

            new D('stock_movement_lines', 'Stock movement lines (ledger)', 'inventory', <<<'SQL'
                SELECT ml.id, ml.stock_movement_id, m.movement_number, m.movement_type, m.status AS movement_status, m.posted_at, m.business_date,
                       m.source_document_type, m.source_document_number, ml.line_no, ml.item_id, i.sku AS item_sku, i.name AS item_name,
                       sil.location_type AS source_location_type, ssl.full_path AS source_location_path, ml.source_warehouse_id, sw.code AS source_warehouse_code,
                       dil.location_type AS destination_location_type, dsl.full_path AS destination_location_path, ml.destination_warehouse_id,
                       dw.code AS destination_warehouse_code, ml.source_condition, ml.destination_condition, ml.inventory_lot_id, l.lot_number,
                       s.serial_number, ml.quantity, ml.unit_cost_snapshot AS unit_cost, ml.total_cost_snapshot AS total_cost, ml.reversed_quantity, p.name AS partner_name
                  FROM stock_movement_lines ml JOIN stock_movements m ON m.id = ml.stock_movement_id JOIN items i ON i.id = ml.item_id
                  JOIN inventory_locations sil ON sil.id = ml.source_inventory_location_id LEFT JOIN storage_locations ssl ON ssl.id = sil.storage_location_id
                  JOIN inventory_locations dil ON dil.id = ml.destination_inventory_location_id LEFT JOIN storage_locations dsl ON dsl.id = dil.storage_location_id
                  LEFT JOIN warehouses sw ON sw.id = ml.source_warehouse_id LEFT JOIN warehouses dw ON dw.id = ml.destination_warehouse_id
                  LEFT JOIN inventory_lots l ON l.id = ml.inventory_lot_id LEFT JOIN item_serials s ON s.id = ml.item_serial_id
                  LEFT JOIN partners p ON p.id = m.partner_id
                SQL, [
                F::uuid('id', 'Line ID'), F::uuid('stock_movement_id', 'Movement ID'), F::string('movement_number', 'Movement'),
                F::enum('movement_type', 'Type', MovementType::class), F::enum('movement_status', 'Movement status', MovementStatus::class),
                F::datetime('posted_at', 'Posted at'), F::date('business_date', 'Business date'), F::enum('source_document_type', 'Document type', DocumentType::class),
                F::string('source_document_number', 'Document'), F::int('line_no', 'Line'), F::uuid('item_id', 'Item ID'), F::string('item_sku', 'SKU'), F::string('item_name', 'Item'),
                F::enum('source_location_type', 'From type', InventoryLocationType::class), F::string('source_location_path', 'From location'),
                F::uuid('source_warehouse_id', 'From warehouse ID'), F::string('source_warehouse_code', 'From warehouse'),
                F::enum('destination_location_type', 'To type', InventoryLocationType::class), F::string('destination_location_path', 'To location'),
                F::uuid('destination_warehouse_id', 'To warehouse ID'), F::string('destination_warehouse_code', 'To warehouse'),
                F::enum('source_condition', 'From condition', StockCondition::class), F::enum('destination_condition', 'To condition', StockCondition::class),
                F::uuid('inventory_lot_id', 'Lot ID'), F::string('lot_number', 'Lot'), F::string('serial_number', 'Serial'), F::qty('quantity', 'Quantity'),
                F::money('unit_cost', 'Unit cost'), F::money('total_cost', 'Total cost'), F::qty('reversed_quantity', 'Reversed'), F::string('partner_name', 'Partner'),
            ], 'inventory.movement.read', warehouseScope: ['source_warehouse_id', 'destination_warehouse_id'],
                relationships: [R::to('movement', 'Movement', 'stock_movements', 'stock_movement_id'), R::to('item', 'Item', 'items', 'item_id'),
                    R::to('lot', 'Lot', 'lots', 'inventory_lot_id')], defaultSort: [['posted_at', 'desc'], ['line_no', 'asc']]),

            new D('lots', 'Lots / batches', 'inventory', <<<'SQL'
                SELECT l.id, l.item_id, i.sku AS item_sku, i.name AS item_name, l.lot_number, l.supplier_id, sp.name AS supplier_name,
                       l.manufacture_date, l.expiry_date, l.best_before_date, l.removal_date, l.qc_status, l.lock_status, l.status,
                       (l.expiry_date - CURRENT_DATE) AS days_to_expiry,
                       CASE WHEN l.expiry_date IS NULL THEN 'no_expiry' WHEN l.expiry_date <= CURRENT_DATE THEN 'expired'
                            WHEN l.expiry_date <= CURRENT_DATE + 7 THEN 'within_7_days' WHEN l.expiry_date <= CURRENT_DATE + 30 THEN 'within_30_days'
                            WHEN l.expiry_date <= CURRENT_DATE + 90 THEN 'within_90_days' ELSE 'beyond_warning' END AS expiry_bucket, l.created_at
                  FROM inventory_lots l JOIN items i ON i.id = l.item_id LEFT JOIN partners sp ON sp.id = l.supplier_id
                SQL, [
                F::uuid('id', 'Lot ID'), F::uuid('item_id', 'Item ID'), F::string('item_sku', 'SKU'), F::string('item_name', 'Item'), F::string('lot_number', 'Lot'),
                F::uuid('supplier_id', 'Supplier ID'), F::string('supplier_name', 'Supplier'), F::date('manufacture_date', 'Manufactured'), F::date('expiry_date', 'Expiry'),
                F::date('best_before_date', 'Best before'), F::date('removal_date', 'Removal date'), F::enum('qc_status', 'QC status', LotQcStatus::class),
                F::enum('lock_status', 'Lock', LotLockStatus::class), F::enum('status', 'Status', LotStatus::class), F::int('days_to_expiry', 'Days to expiry'),
                F::enum('expiry_bucket', 'Expiry horizon', \App\Domains\Inventory\Enums\ExpiryHorizon::class), F::datetime('created_at', 'Registered'),
            ], 'inventory.lot.read', relationships: [R::to('item', 'Item', 'items', 'item_id'), R::many('positions', 'Positions', 'stock_positions', 'inventory_lot_id')],
                defaultSort: [['expiry_date', 'asc']]),

            new D('serials', 'Serial numbers', 'inventory', <<<'SQL'
                SELECT s.id, s.item_id, i.sku AS item_sku, i.name AS item_name, s.serial_number, l.lot_number, s.lifecycle_status, s.current_condition,
                       s.current_warehouse_id AS warehouse_id, w.code AS warehouse_code, w.branch_id, sl.full_path AS location_path, s.warranty_until, s.updated_at
                  FROM item_serials s JOIN items i ON i.id = s.item_id LEFT JOIN inventory_lots l ON l.id = s.inventory_lot_id
                  LEFT JOIN warehouses w ON w.id = s.current_warehouse_id LEFT JOIN inventory_locations il ON il.id = s.current_inventory_location_id
                  LEFT JOIN storage_locations sl ON sl.id = il.storage_location_id
                SQL, [
                F::uuid('id', 'Serial ID'), F::uuid('item_id', 'Item ID'), F::string('item_sku', 'SKU'), F::string('item_name', 'Item'), F::string('serial_number', 'Serial'),
                F::string('lot_number', 'Lot'), F::enum('lifecycle_status', 'Lifecycle', SerialStatus::class), F::enum('current_condition', 'Condition', StockCondition::class),
                F::uuid('warehouse_id', 'Warehouse ID'), F::string('warehouse_code', 'Warehouse'), F::uuid('branch_id', 'Branch ID'), F::string('location_path', 'Location'),
                F::date('warranty_until', 'Warranty until'), F::datetime('updated_at', 'Last change'),
            ], 'inventory.serial.read', warehouseScope: ['warehouse_id'], branchScope: 'branch_id', relationships: [R::to('item', 'Item', 'items', 'item_id')]),

            new D('valuation_by_item_warehouse', 'Valuation by item and warehouse', 'inventory', <<<SQL
                SELECT p.id, p.item_id, i.sku AS item_sku, i.name AS item_name, c.full_path AS category_path, NULLIF(p.warehouse_id, $s) AS warehouse_id,
                       COALESCE(w.code, 'COMPANY') AS warehouse_code, w.branch_id, i.costing_method, p.quantity, p.total_value, p.average_cost, p.updated_at
                  FROM item_cost_projections p JOIN items i ON i.id = p.item_id JOIN item_categories c ON c.id = i.category_id
                  LEFT JOIN warehouses w ON w.id = p.warehouse_id
                SQL, [
                F::uuid('id', 'Projection ID'), F::uuid('item_id', 'Item ID'), F::string('item_sku', 'SKU'), F::string('item_name', 'Item'), F::string('category_path', 'Category'),
                F::uuid('warehouse_id', 'Warehouse ID'), F::string('warehouse_code', 'Valuation bucket'), F::uuid('branch_id', 'Branch ID'),
                F::enum('costing_method', 'Costing method', CostingMethod::class), F::qty('quantity', 'Quantity'), F::money('total_value', 'Value'),
                F::money('average_cost', 'Average cost'), F::datetime('updated_at', 'Updated'),
            ], 'inventory.valuation.read', warehouseScope: ['warehouse_id'], branchScope: 'branch_id', classification: C::COST_SENSITIVE,
                relationships: [R::to('item', 'Item', 'items', 'item_id')], defaultSort: [['total_value', 'desc']]),

            new D('valuation_layers', 'FIFO valuation layers', 'inventory', <<<SQL
                SELECT v.id, v.item_id, i.sku AS item_sku, i.name AS item_name, NULLIF(v.warehouse_id, $s) AS warehouse_id, w.code AS warehouse_code, w.branch_id,
                       v.layer_date, v.original_quantity, v.remaining_quantity, v.unit_cost, round(v.remaining_quantity * v.unit_cost, 4) AS remaining_value, v.status
                  FROM valuation_layers v JOIN items i ON i.id = v.item_id LEFT JOIN warehouses w ON w.id = v.warehouse_id
                SQL, [
                F::uuid('id', 'Layer ID'), F::uuid('item_id', 'Item ID'), F::string('item_sku', 'SKU'), F::string('item_name', 'Item'), F::uuid('warehouse_id', 'Warehouse ID'),
                F::string('warehouse_code', 'Warehouse'), F::uuid('branch_id', 'Branch ID'), F::datetime('layer_date', 'Layer date'), F::qty('original_quantity', 'Original qty'),
                F::qty('remaining_quantity', 'Remaining qty'), F::money('unit_cost', 'Unit cost'), F::money('remaining_value', 'Remaining value'),
                F::enum('status', 'Status', ValuationLayerStatus::class),
            ], 'inventory.valuation.read', warehouseScope: ['warehouse_id'], branchScope: 'branch_id', classification: C::COST_SENSITIVE,
                defaultSort: [['layer_date', 'asc']]),

            new D('in_transit_positions', 'Stock in transit', 'transfers', <<<SQL
                SELECT b.id, b.item_id, i.sku AS item_sku, i.name AS item_name, il.lane_source_warehouse_id AS source_warehouse_id, sw.code AS source_warehouse_code,
                       il.lane_destination_warehouse_id AS destination_warehouse_id, dw.code AS destination_warehouse_code, l.lot_number, b.stock_condition,
                       b.quantity_on_hand AS quantity, b.total_value_projection AS total_value, b.last_movement_at,
                       (CURRENT_DATE - b.last_movement_at::date) AS days_in_transit
                  FROM inventory_balances b JOIN inventory_locations il ON il.id = b.inventory_location_id JOIN items i ON i.id = b.item_id
                  LEFT JOIN warehouses sw ON sw.id = il.lane_source_warehouse_id LEFT JOIN warehouses dw ON dw.id = il.lane_destination_warehouse_id
                  LEFT JOIN inventory_lots l ON l.id = NULLIF(b.inventory_lot_id, $s)
                 WHERE il.location_type = 'in_transit' AND b.quantity_on_hand <> 0
                SQL, [
                F::uuid('id', 'Position ID'), F::uuid('item_id', 'Item ID'), F::string('item_sku', 'SKU'), F::string('item_name', 'Item'),
                F::uuid('source_warehouse_id', 'From warehouse ID'), F::string('source_warehouse_code', 'From'), F::uuid('destination_warehouse_id', 'To warehouse ID'),
                F::string('destination_warehouse_code', 'To'), F::string('lot_number', 'Lot'), F::enum('stock_condition', 'Condition', StockCondition::class),
                F::qty('quantity', 'Quantity'), F::money('total_value', 'Value'), F::datetime('last_movement_at', 'Dispatched / last movement'),
                F::int('days_in_transit', 'Days in transit'),
            ], 'transfers.transfer.read', warehouseScope: ['source_warehouse_id', 'destination_warehouse_id'], defaultSort: [['days_in_transit', 'desc']]),

            new D('lot_trace', 'Lot traceability (forward)', 'inventory', <<<'SQL'
                SELECT ml.id, ml.inventory_lot_id, l.lot_number, ml.item_id, i.sku AS item_sku, i.name AS item_name, m.movement_number, m.movement_type,
                       m.posted_at, m.source_document_type, m.source_document_number, sil.location_type AS source_location_type, ml.source_warehouse_id,
                       sw.code AS source_warehouse_code, dil.location_type AS destination_location_type, ml.destination_warehouse_id,
                       dw.code AS destination_warehouse_code, p.code AS partner_code, p.name AS partner_name, s.serial_number, ml.quantity
                  FROM stock_movement_lines ml JOIN stock_movements m ON m.id = ml.stock_movement_id JOIN inventory_lots l ON l.id = ml.inventory_lot_id
                  JOIN items i ON i.id = ml.item_id JOIN inventory_locations sil ON sil.id = ml.source_inventory_location_id
                  JOIN inventory_locations dil ON dil.id = ml.destination_inventory_location_id LEFT JOIN warehouses sw ON sw.id = ml.source_warehouse_id
                  LEFT JOIN warehouses dw ON dw.id = ml.destination_warehouse_id LEFT JOIN partners p ON p.id = m.partner_id
                  LEFT JOIN item_serials s ON s.id = ml.item_serial_id
                SQL, [
                F::uuid('id', 'Line ID'), F::uuid('inventory_lot_id', 'Lot ID'), F::string('lot_number', 'Lot'), F::uuid('item_id', 'Item ID'), F::string('item_sku', 'SKU'),
                F::string('item_name', 'Item'), F::string('movement_number', 'Movement'), F::enum('movement_type', 'Type', MovementType::class), F::datetime('posted_at', 'Posted at'),
                F::enum('source_document_type', 'Document type', DocumentType::class), F::string('source_document_number', 'Document'),
                F::enum('source_location_type', 'From type', InventoryLocationType::class), F::uuid('source_warehouse_id', 'From warehouse ID'),
                F::string('source_warehouse_code', 'From warehouse'), F::enum('destination_location_type', 'To type', InventoryLocationType::class),
                F::uuid('destination_warehouse_id', 'To warehouse ID'), F::string('destination_warehouse_code', 'To warehouse'), F::string('partner_code', 'Partner code'),
                F::string('partner_name', 'Partner'), F::string('serial_number', 'Serial'), F::qty('quantity', 'Quantity'),
            ], 'inventory.trace.read', relationships: [R::to('lot', 'Lot', 'lots', 'inventory_lot_id')], defaultSort: [['posted_at', 'asc']]),
        ];
    }
}
