<?php

declare(strict_types=1);

namespace App\Domains\ReportingEngine\Datasets;

use App\Domains\ReportingEngine\Catalog\DatasetDefinition as D;
use App\Domains\ReportingEngine\Catalog\DatasetField as F;
use App\Domains\ReportingEngine\Catalog\DatasetRelationship as R;
use App\Domains\ReportingEngine\Catalog\ProvidesDatasets;
use App\Domains\Transfers\Enums\DiscrepancyStatus;
use App\Domains\Transfers\Enums\DiscrepancyType;
use App\Domains\Transfers\Enums\TransferEventStatus;
use App\Domains\Transfers\Enums\TransferStatus;
use App\Support\Enums\Priority;

/** Allocation & inter-branch distribution (Chapter 08 §8.4). Visible from either side. */
final class TransferDatasets implements ProvidesDatasets
{
    public static function datasets(): array
    {
        $sides = ['source_warehouse_id', 'destination_warehouse_id'];

        return [
            new D('transfer_orders', 'Transfer orders', 'transfers', <<<'SQL'
                SELECT t.id, t.document_number, t.status, t.source_warehouse_id, sw.code AS source_warehouse_code, t.destination_warehouse_id,
                       dw.code AS destination_warehouse_code, t.branch_id, t.document_date, t.priority, t.required_by, t.reason, t.allocation_plan_id,
                       t.submitted_at, t.approved_at, t.closed_at, t.created_at,
                       (SELECT min(d.dispatched_at) FROM transfer_dispatches d WHERE d.transfer_order_id = t.id) AS first_dispatched_at,
                       (SELECT max(r.received_at) FROM transfer_receipts r WHERE r.transfer_order_id = t.id) AS last_received_at,
                       (SELECT count(*) FROM transfer_discrepancies x WHERE x.transfer_order_id = t.id) AS discrepancy_count
                  FROM transfer_orders t JOIN warehouses sw ON sw.id = t.source_warehouse_id JOIN warehouses dw ON dw.id = t.destination_warehouse_id
                SQL, [
                F::uuid('id', 'Transfer ID'), F::string('document_number', 'Transfer'), F::enum('status', 'Status', TransferStatus::class),
                F::uuid('source_warehouse_id', 'From warehouse ID'), F::string('source_warehouse_code', 'From'), F::uuid('destination_warehouse_id', 'To warehouse ID'),
                F::string('destination_warehouse_code', 'To'), F::uuid('branch_id', 'Branch ID'), F::date('document_date', 'Date'), F::enum('priority', 'Priority', Priority::class),
                F::date('required_by', 'Required by'), F::string('reason', 'Reason'), F::uuid('allocation_plan_id', 'Allocation plan ID'), F::datetime('submitted_at', 'Submitted'),
                F::datetime('approved_at', 'Approved'), F::datetime('closed_at', 'Closed'), F::datetime('created_at', 'Created'),
                F::datetime('first_dispatched_at', 'First dispatch'), F::datetime('last_received_at', 'Last receipt'), F::int('discrepancy_count', 'Discrepancies'),
            ], 'transfers.transfer.read', warehouseScope: $sides, relationships: [R::many('lines', 'Lines', 'transfer_order_lines', 'transfer_order_id')],
                defaultSort: [['document_date', 'desc']]),

            new D('transfer_order_lines', 'Transfer order lines (conservation)', 'transfers', <<<'SQL'
                SELECT l.id, l.transfer_order_id, t.document_number, t.status AS transfer_status, t.source_warehouse_id, sw.code AS source_warehouse_code,
                       t.destination_warehouse_id, dw.code AS destination_warehouse_code, l.line_no, l.item_id, i.sku AS item_sku, i.name AS item_name,
                       l.requested_base_quantity AS requested_quantity, l.approved_base_quantity AS approved_quantity, l.dispatched_base_quantity AS dispatched_quantity,
                       l.received_base_quantity AS received_quantity, l.resolved_base_quantity AS resolved_quantity, l.cancelled_base_quantity AS cancelled_quantity,
                       (l.dispatched_base_quantity - l.received_base_quantity - l.resolved_base_quantity) AS in_transit_quantity,
                       CASE WHEN l.approved_base_quantity > 0 THEN round(100.0 * l.received_base_quantity / l.approved_base_quantity, 2) END AS fill_rate_percent
                  FROM transfer_order_lines l JOIN transfer_orders t ON t.id = l.transfer_order_id JOIN items i ON i.id = l.item_id
                  JOIN warehouses sw ON sw.id = t.source_warehouse_id JOIN warehouses dw ON dw.id = t.destination_warehouse_id
                SQL, [
                F::uuid('id', 'Line ID'), F::uuid('transfer_order_id', 'Transfer ID'), F::string('document_number', 'Transfer'),
                F::enum('transfer_status', 'Transfer status', TransferStatus::class), F::uuid('source_warehouse_id', 'From warehouse ID'), F::string('source_warehouse_code', 'From'),
                F::uuid('destination_warehouse_id', 'To warehouse ID'), F::string('destination_warehouse_code', 'To'), F::int('line_no', 'Line'), F::uuid('item_id', 'Item ID'),
                F::string('item_sku', 'SKU'), F::string('item_name', 'Item'), F::qty('requested_quantity', 'Requested'), F::qty('approved_quantity', 'Approved'),
                F::qty('dispatched_quantity', 'Dispatched'), F::qty('received_quantity', 'Received'), F::qty('resolved_quantity', 'Resolved'),
                F::qty('cancelled_quantity', 'Cancelled'), F::qty('in_transit_quantity', 'In transit'), F::decimal('fill_rate_percent', 'Fill rate %'),
            ], 'transfers.transfer.read', warehouseScope: $sides,
                relationships: [R::to('transfer', 'Transfer', 'transfer_orders', 'transfer_order_id'), R::to('item', 'Item', 'items', 'item_id')]),

            new D('transfer_dispatches', 'Transfer dispatches', 'transfers', <<<'SQL'
                SELECT d.id, d.document_number, d.transfer_order_id, t.document_number AS transfer_number, d.status, t.source_warehouse_id, t.destination_warehouse_id,
                       d.dispatched_at, d.expected_arrival, d.tracking_reference, d.vehicle_reference, c.name AS carrier_name
                  FROM transfer_dispatches d JOIN transfer_orders t ON t.id = d.transfer_order_id LEFT JOIN partners c ON c.id = d.carrier_partner_id
                SQL, [
                F::uuid('id', 'Dispatch ID'), F::string('document_number', 'Dispatch'), F::uuid('transfer_order_id', 'Transfer ID'), F::string('transfer_number', 'Transfer'),
                F::enum('status', 'Status', TransferEventStatus::class), F::uuid('source_warehouse_id', 'From warehouse ID'), F::uuid('destination_warehouse_id', 'To warehouse ID'),
                F::datetime('dispatched_at', 'Dispatched'), F::datetime('expected_arrival', 'Expected arrival'), F::string('tracking_reference', 'Tracking'),
                F::string('vehicle_reference', 'Vehicle'), F::string('carrier_name', 'Carrier'),
            ], 'transfers.transfer.read', warehouseScope: $sides, defaultSort: [['dispatched_at', 'desc']]),

            new D('transfer_receipts', 'Transfer receipts', 'transfers', <<<'SQL'
                SELECT r.id, r.document_number, r.transfer_order_id, t.document_number AS transfer_number, r.status, t.source_warehouse_id, t.destination_warehouse_id,
                       r.received_at, TIMESTAMPDIFF(MICROSECOND, (SELECT min(d.dispatched_at) FROM transfer_dispatches d WHERE d.transfer_order_id = t.id), r.received_at) / 3600000000.0 AS lead_time_hours
                  FROM transfer_receipts r JOIN transfer_orders t ON t.id = r.transfer_order_id
                SQL, [
                F::uuid('id', 'Receipt ID'), F::string('document_number', 'Receipt'), F::uuid('transfer_order_id', 'Transfer ID'), F::string('transfer_number', 'Transfer'),
                F::enum('status', 'Status', TransferEventStatus::class), F::uuid('source_warehouse_id', 'From warehouse ID'), F::uuid('destination_warehouse_id', 'To warehouse ID'),
                F::datetime('received_at', 'Received'), F::decimal('lead_time_hours', 'Lead time (h)'),
            ], 'transfers.transfer.read', warehouseScope: $sides, defaultSort: [['received_at', 'desc']]),

            new D('transfer_discrepancies', 'Transfer discrepancies', 'transfers', <<<'SQL'
                SELECT x.id, x.document_number, x.transfer_order_id, t.document_number AS transfer_number, t.source_warehouse_id, t.destination_warehouse_id,
                       x.item_id, i.sku AS item_sku, i.name AS item_name, l.lot_number, x.discrepancy_type, x.base_quantity AS quantity,
                       x.resolved_base_quantity AS resolved_quantity, x.status, x.created_at
                  FROM transfer_discrepancies x JOIN transfer_orders t ON t.id = x.transfer_order_id JOIN items i ON i.id = x.item_id
                  LEFT JOIN inventory_lots l ON l.id = x.inventory_lot_id
                SQL, [
                F::uuid('id', 'Discrepancy ID'), F::string('document_number', 'Discrepancy'), F::uuid('transfer_order_id', 'Transfer ID'), F::string('transfer_number', 'Transfer'),
                F::uuid('source_warehouse_id', 'From warehouse ID'), F::uuid('destination_warehouse_id', 'To warehouse ID'), F::uuid('item_id', 'Item ID'),
                F::string('item_sku', 'SKU'), F::string('item_name', 'Item'), F::string('lot_number', 'Lot'), F::enum('discrepancy_type', 'Type', DiscrepancyType::class),
                F::qty('quantity', 'Quantity'), F::qty('resolved_quantity', 'Resolved'), F::enum('status', 'Status', DiscrepancyStatus::class), F::datetime('created_at', 'Reported'),
            ], 'transfers.discrepancy.read', warehouseScope: $sides, defaultSort: [['created_at', 'desc']]),
        ];
    }
}
