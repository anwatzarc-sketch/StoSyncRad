<?php

declare(strict_types=1);

namespace App\Domains\ReportingEngine\Datasets;

use App\Domains\Catalog\Enums\ReplenishmentMethod;
use App\Domains\Planning\Enums\AllocationPlanStatus;
use App\Domains\Planning\Enums\AllocationPolicy;
use App\Domains\ReportingEngine\Catalog\DatasetDefinition as D;
use App\Domains\ReportingEngine\Catalog\DatasetField as F;
use App\Domains\ReportingEngine\Catalog\DatasetRelationship as R;
use App\Domains\ReportingEngine\Catalog\ProvidesDatasets;

/** Planning and performance monitoring (Chapter 08 §8.1, §8.14). */
final class PlanningDatasets implements ProvidesDatasets
{
    public static function datasets(): array
    {
        return [
            new D('allocation_plans', 'Allocation plans', 'planning', <<<'SQL'
                SELECT p.id, p.document_number, p.status, p.policy, p.warehouse_id, w.code AS warehouse_code, p.branch_id, p.period_start, p.period_end,
                       p.approved_at, p.released_at, (SELECT COALESCE(sum(l.quantity), 0) FROM allocation_plan_lines l WHERE l.allocation_plan_id = p.id) AS allocated_quantity,
                       (SELECT count(DISTINCT l.destination_warehouse_id) FROM allocation_plan_lines l WHERE l.allocation_plan_id = p.id) AS destination_count
                  FROM allocation_plans p JOIN warehouses w ON w.id = p.warehouse_id
                SQL, [
                F::uuid('id', 'Plan ID'), F::string('document_number', 'Plan'), F::enum('status', 'Status', AllocationPlanStatus::class),
                F::enum('policy', 'Policy', AllocationPolicy::class), F::uuid('warehouse_id', 'Source warehouse ID'), F::string('warehouse_code', 'Source warehouse'),
                F::uuid('branch_id', 'Branch ID'), F::date('period_start', 'Period start'), F::date('period_end', 'Period end'), F::datetime('approved_at', 'Approved'),
                F::datetime('released_at', 'Released'), F::qty('allocated_quantity', 'Allocated'), F::int('destination_count', 'Destinations'),
            ], 'planning.allocation_plan.read', feature: 'feature.allocation_planning', defaultSort: [['period_start', 'desc']]),

            new D('reorder_status', 'Reorder status (available vs rules)', 'planning', <<<'SQL'
                SELECT r.id, r.item_id, i.sku AS item_sku, i.name AS item_name, r.warehouse_id, w.code AS warehouse_code, w.branch_id, r.replenishment_method,
                       r.min_qty, r.reorder_point, r.safety_stock, r.max_qty, r.reorder_qty,
                       COALESCE(a.available, 0) AS available_quantity,
                       (COALESCE(a.available, 0) <= GREATEST(r.reorder_point, r.safety_stock)) AS is_below_reorder_point,
                       sp.name AS preferred_supplier_name, sw.code AS preferred_source_warehouse_code
                  FROM item_reorder_rules r JOIN items i ON i.id = r.item_id JOIN warehouses w ON w.id = r.warehouse_id
                  LEFT JOIN partners sp ON sp.id = r.preferred_supplier_id LEFT JOIN warehouses sw ON sw.id = r.preferred_source_warehouse_id
                  LEFT JOIN LATERAL (SELECT sum(b.quantity_on_hand - b.quantity_reserved) AS available
                                       FROM inventory_balances b JOIN inventory_locations il ON il.id = b.inventory_location_id
                                      WHERE b.item_id = r.item_id AND b.warehouse_id = r.warehouse_id AND b.stock_condition = 'available'
                                        AND il.location_type = 'physical') a ON true
                 WHERE r.is_active AND r.warehouse_id IS NOT NULL
                SQL, [
                F::uuid('id', 'Rule ID'), F::uuid('item_id', 'Item ID'), F::string('item_sku', 'SKU'), F::string('item_name', 'Item'), F::uuid('warehouse_id', 'Warehouse ID'),
                F::string('warehouse_code', 'Warehouse'), F::uuid('branch_id', 'Branch ID'), F::enum('replenishment_method', 'Method', ReplenishmentMethod::class),
                F::qty('min_qty', 'Minimum'), F::qty('reorder_point', 'Reorder point'), F::qty('safety_stock', 'Safety stock'), F::qty('max_qty', 'Maximum'),
                F::qty('reorder_qty', 'Reorder qty'), F::qty('available_quantity', 'Available'), F::bool('is_below_reorder_point', 'Below reorder point'),
                F::string('preferred_supplier_name', 'Preferred supplier'), F::string('preferred_source_warehouse_code', 'Preferred source'),
            ], 'planning.replenishment.read', warehouseScope: ['warehouse_id'], branchScope: 'branch_id',
                relationships: [R::to('item', 'Item', 'items', 'item_id')], defaultSort: [['is_below_reorder_point', 'desc'], ['item_sku', 'asc']]),

            new D('demand_history', 'Demand history (monthly, from the ledger)', 'planning', <<<'SQL'
                SELECT md5(ml.item_id::text || COALESCE(ml.source_warehouse_id::text, '') || date_trunc('month', m.business_date)::text)::uuid AS id,
                       ml.item_id, i.sku AS item_sku, i.name AS item_name, ml.source_warehouse_id AS warehouse_id, w.code AS warehouse_code, w.branch_id,
                       date_trunc('month', m.business_date)::date AS period_month,
                       sum(ml.quantity) FILTER (WHERE m.movement_type IN ('issue', 'production_issue')) AS issued_quantity,
                       sum(ml.quantity) FILTER (WHERE m.movement_type = 'sale_delivery') AS sold_quantity,
                       sum(ml.quantity) FILTER (WHERE m.movement_type = 'transfer_dispatch') AS transferred_out_quantity,
                       sum(ml.quantity) AS total_demand_quantity, sum(ml.total_cost_snapshot) AS demand_cost
                  FROM stock_movement_lines ml JOIN stock_movements m ON m.id = ml.stock_movement_id JOIN items i ON i.id = ml.item_id
                  LEFT JOIN warehouses w ON w.id = ml.source_warehouse_id
                 WHERE m.movement_type IN ('issue', 'production_issue', 'sale_delivery', 'transfer_dispatch')
                 GROUP BY ml.item_id, i.sku, i.name, ml.source_warehouse_id, w.code, w.branch_id, date_trunc('month', m.business_date)
                SQL, [
                F::uuid('id', 'Row ID'), F::uuid('item_id', 'Item ID'), F::string('item_sku', 'SKU'), F::string('item_name', 'Item'), F::uuid('warehouse_id', 'Warehouse ID'),
                F::string('warehouse_code', 'Warehouse'), F::uuid('branch_id', 'Branch ID'), F::date('period_month', 'Month'), F::qty('issued_quantity', 'Issued'),
                F::qty('sold_quantity', 'Sold'), F::qty('transferred_out_quantity', 'Transferred out'), F::qty('total_demand_quantity', 'Total demand'),
                F::money('demand_cost', 'Demand at cost'),
            ], 'planning.demand.read', warehouseScope: ['warehouse_id'], branchScope: 'branch_id', relationships: [R::to('item', 'Item', 'items', 'item_id')],
                defaultSort: [['period_month', 'desc']]),
        ];
    }
}
