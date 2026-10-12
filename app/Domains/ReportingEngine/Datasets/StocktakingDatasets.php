<?php

declare(strict_types=1);

namespace App\Domains\ReportingEngine\Datasets;

use App\Domains\Inventory\Enums\StockCondition;
use App\Domains\ReportingEngine\Catalog\DatasetDefinition as D;
use App\Domains\ReportingEngine\Catalog\DatasetField as F;
use App\Domains\ReportingEngine\Catalog\DatasetRelationship as R;
use App\Domains\ReportingEngine\Catalog\ProvidesDatasets;
use App\Domains\Stocktaking\Enums\AdjustmentType;
use App\Domains\Stocktaking\Enums\CountLineStatus;
use App\Domains\Stocktaking\Enums\StockAdjustmentStatus;
use App\Domains\Stocktaking\Enums\StocktakeFreezePolicy;
use App\Domains\Stocktaking\Enums\StocktakeStatus;
use App\Domains\Stocktaking\Enums\StocktakeType;

/** Counts, variances and adjustments (Chapter 08 §8.10–8.11). */
final class StocktakingDatasets implements ProvidesDatasets
{
    public static function datasets(): array
    {
        return [
            new D('stocktakes', 'Stocktakes', 'stocktaking', <<<'SQL'
                SELECT s.id, s.document_number, s.status, s.stocktake_type, s.freeze_policy, s.is_blind, s.warehouse_id, w.code AS warehouse_code, s.branch_id,
                       s.document_date, s.scheduled_for, s.snapshot_at, s.posted_at,
                       (SELECT count(*) FROM stocktake_lines l WHERE l.stocktake_id = s.id) AS line_count,
                       (SELECT count(*) FROM stocktake_lines l WHERE l.stocktake_id = s.id AND l.variance_quantity <> 0) AS variance_lines,
                       (SELECT COALESCE(sum(l.variance_value), 0) FROM stocktake_lines l WHERE l.stocktake_id = s.id) AS net_variance_value,
                       (SELECT COALESCE(sum(abs(l.variance_value)), 0) FROM stocktake_lines l WHERE l.stocktake_id = s.id) AS absolute_variance_value
                  FROM stocktakes s JOIN warehouses w ON w.id = s.warehouse_id
                SQL, [
                F::uuid('id', 'Stocktake ID'), F::string('document_number', 'Stocktake'), F::enum('status', 'Status', StocktakeStatus::class),
                F::enum('stocktake_type', 'Type', StocktakeType::class), F::enum('freeze_policy', 'Freeze policy', StocktakeFreezePolicy::class), F::bool('is_blind', 'Blind'),
                F::uuid('warehouse_id', 'Warehouse ID'), F::string('warehouse_code', 'Warehouse'), F::uuid('branch_id', 'Branch ID'), F::date('document_date', 'Date'),
                F::date('scheduled_for', 'Scheduled'), F::datetime('snapshot_at', 'Snapshot'), F::datetime('posted_at', 'Posted'), F::int('line_count', 'Lines'),
                F::int('variance_lines', 'Lines with variance'), F::money('net_variance_value', 'Net variance value'), F::money('absolute_variance_value', 'Absolute variance value'),
            ], 'stocktaking.stocktake.read', warehouseScope: ['warehouse_id'], branchScope: 'branch_id',
                relationships: [R::many('lines', 'Lines', 'stocktake_lines', 'stocktake_id')], defaultSort: [['document_date', 'desc']]),

            new D('stocktake_lines', 'Stocktake lines (variances)', 'stocktaking', <<<'SQL'
                SELECT l.id, l.stocktake_id, s.document_number, s.status AS stocktake_status, s.warehouse_id, w.code AS warehouse_code, s.branch_id, l.line_no,
                       l.item_id, i.sku AS item_sku, i.name AS item_name, sl.full_path AS location_path, lt.lot_number, l.stock_condition, l.snapshot_quantity,
                       l.movement_since_snapshot, l.counted_quantity, l.recount_quantity, l.final_quantity, l.variance_quantity, l.variance_value, l.count_status,
                       l.is_unexpected, l.counted_at
                  FROM stocktake_lines l JOIN stocktakes s ON s.id = l.stocktake_id JOIN warehouses w ON w.id = s.warehouse_id JOIN items i ON i.id = l.item_id
                  JOIN storage_locations sl ON sl.id = l.storage_location_id LEFT JOIN inventory_lots lt ON lt.id = l.inventory_lot_id
                SQL, [
                F::uuid('id', 'Line ID'), F::uuid('stocktake_id', 'Stocktake ID'), F::string('document_number', 'Stocktake'), F::enum('stocktake_status', 'Stocktake status', StocktakeStatus::class),
                F::uuid('warehouse_id', 'Warehouse ID'), F::string('warehouse_code', 'Warehouse'), F::uuid('branch_id', 'Branch ID'), F::int('line_no', 'Line'),
                F::uuid('item_id', 'Item ID'), F::string('item_sku', 'SKU'), F::string('item_name', 'Item'), F::string('location_path', 'Location'), F::string('lot_number', 'Lot'),
                F::enum('stock_condition', 'Condition', StockCondition::class), F::qty('snapshot_quantity', 'Book qty (snapshot)'), F::qty('movement_since_snapshot', 'Moved since snapshot'),
                F::qty('counted_quantity', 'Counted'), F::qty('recount_quantity', 'Recounted'), F::qty('final_quantity', 'Final'), F::qty('variance_quantity', 'Variance'),
                F::money('variance_value', 'Variance value'), F::enum('count_status', 'Count status', CountLineStatus::class), F::bool('is_unexpected', 'Found stock'),
                F::datetime('counted_at', 'Counted at'),
            ], 'stocktaking.stocktake.read', warehouseScope: ['warehouse_id'], branchScope: 'branch_id', relationships: [R::to('item', 'Item', 'items', 'item_id')]),

            new D('stock_adjustments', 'Stock adjustments', 'stocktaking', <<<'SQL'
                SELECT a.id, a.document_number, a.status, a.adjustment_type, a.warehouse_id, w.code AS warehouse_code, a.branch_id, a.document_date, a.reason,
                       a.source_stocktake_id, a.total_value, a.created_by, a.approved_by, a.approved_at, a.posted_at
                  FROM stock_adjustments a JOIN warehouses w ON w.id = a.warehouse_id
                SQL, [
                F::uuid('id', 'Adjustment ID'), F::string('document_number', 'Adjustment'), F::enum('status', 'Status', StockAdjustmentStatus::class),
                F::enum('adjustment_type', 'Type', AdjustmentType::class), F::uuid('warehouse_id', 'Warehouse ID'), F::string('warehouse_code', 'Warehouse'),
                F::uuid('branch_id', 'Branch ID'), F::date('document_date', 'Date'), F::string('reason', 'Reason'), F::uuid('source_stocktake_id', 'From stocktake'),
                F::money('total_value', 'Value'), F::uuid('created_by', 'Created by'), F::uuid('approved_by', 'Approved by'), F::datetime('approved_at', 'Approved'),
                F::datetime('posted_at', 'Posted'),
            ], 'stocktaking.adjustment.read', warehouseScope: ['warehouse_id'], branchScope: 'branch_id',
                relationships: [R::many('lines', 'Lines', 'stock_adjustment_lines', 'stock_adjustment_id')], defaultSort: [['document_date', 'desc']]),

            new D('stock_adjustment_lines', 'Stock adjustment lines', 'stocktaking', <<<'SQL'
                SELECT l.id, l.stock_adjustment_id, a.document_number, a.status AS adjustment_status, a.adjustment_type, a.warehouse_id, w.code AS warehouse_code, a.branch_id,
                       l.line_no, l.item_id, i.sku AS item_sku, i.name AS item_name, sl.full_path AS location_path, COALESCE(lt.lot_number, l.lot_number) AS lot_number,
                       l.stock_condition, l.quantity, l.unit_cost, l.value, l.reason
                  FROM stock_adjustment_lines l JOIN stock_adjustments a ON a.id = l.stock_adjustment_id JOIN warehouses w ON w.id = a.warehouse_id JOIN items i ON i.id = l.item_id
                  JOIN storage_locations sl ON sl.id = l.storage_location_id LEFT JOIN inventory_lots lt ON lt.id = l.inventory_lot_id
                SQL, [
                F::uuid('id', 'Line ID'), F::uuid('stock_adjustment_id', 'Adjustment ID'), F::string('document_number', 'Adjustment'),
                F::enum('adjustment_status', 'Adjustment status', StockAdjustmentStatus::class), F::enum('adjustment_type', 'Type', AdjustmentType::class),
                F::uuid('warehouse_id', 'Warehouse ID'), F::string('warehouse_code', 'Warehouse'), F::uuid('branch_id', 'Branch ID'), F::int('line_no', 'Line'),
                F::uuid('item_id', 'Item ID'), F::string('item_sku', 'SKU'), F::string('item_name', 'Item'), F::string('location_path', 'Location'), F::string('lot_number', 'Lot'),
                F::enum('stock_condition', 'Condition', StockCondition::class), F::qty('quantity', 'Quantity (±)'), F::money('unit_cost', 'Unit cost'), F::money('value', 'Value'),
                F::string('reason', 'Line reason'),
            ], 'stocktaking.adjustment.read', warehouseScope: ['warehouse_id'], branchScope: 'branch_id', relationships: [R::to('item', 'Item', 'items', 'item_id')]),
        ];
    }
}
