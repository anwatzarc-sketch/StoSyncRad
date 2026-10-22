<?php

declare(strict_types=1);

namespace App\Domains\ReportingEngine\Datasets;

use App\Domains\Fulfillment\Enums\CustomerReturnStatus;
use App\Domains\Fulfillment\Enums\InternalReturnStatus;
use App\Domains\Fulfillment\Enums\SalesChannel;
use App\Domains\Fulfillment\Enums\SalesOrderStatus;
use App\Domains\Fulfillment\Enums\ShipmentStatus;
use App\Domains\Fulfillment\Enums\StockIssueStatus;
use App\Domains\Fulfillment\Enums\StockRequestStatus;
use App\Domains\ReportingEngine\Catalog\DatasetDefinition as D;
use App\Domains\ReportingEngine\Catalog\DatasetField as F;
use App\Domains\ReportingEngine\Catalog\DatasetRelationship as R;
use App\Domains\ReportingEngine\Catalog\ProvidesDatasets;
use App\Support\Enums\DataClassification as C;
use App\Support\Enums\Priority;

/** Requests, issues, returns, retail & e-commerce sales (Chapter 08 §8.6–8.9). */
final class FulfillmentDatasets implements ProvidesDatasets
{
    public static function datasets(): array
    {
        $wh = fn () => [F::uuid('warehouse_id', 'Warehouse ID'), F::string('warehouse_code', 'Warehouse'), F::uuid('branch_id', 'Branch ID'), F::date('document_date', 'Date')];

        return [
            new D('stock_requests', 'Stock requests', 'fulfillment', <<<'SQL'
                SELECT r.id, r.document_number, r.status, r.warehouse_id, w.code AS warehouse_code, r.branch_id, r.document_date, r.priority, r.required_by,
                       r.purpose, r.cost_centre, r.requester_id, p.name AS recipient_name, r.submitted_at, r.approved_at, r.created_at,
                       (SELECT COALESCE(sum(l.approved_base_quantity), 0) FROM stock_request_lines l WHERE l.stock_request_id = r.id) AS approved_quantity,
                       (SELECT COALESCE(sum(l.fulfilled_base_quantity), 0) FROM stock_request_lines l WHERE l.stock_request_id = r.id) AS fulfilled_quantity
                  FROM stock_requests r JOIN warehouses w ON w.id = r.warehouse_id LEFT JOIN partners p ON p.id = r.recipient_partner_id
                SQL, array_merge([F::uuid('id', 'Request ID'), F::string('document_number', 'Request'), F::enum('status', 'Status', StockRequestStatus::class)], $wh(), [
                F::enum('priority', 'Priority', Priority::class), F::date('required_by', 'Required by'), F::string('purpose', 'Purpose'), F::string('cost_centre', 'Cost centre'),
                F::uuid('requester_id', 'Requester ID'), F::string('recipient_name', 'Recipient'), F::datetime('submitted_at', 'Submitted'), F::datetime('approved_at', 'Approved'),
                F::datetime('created_at', 'Created'), F::qty('approved_quantity', 'Approved qty'), F::qty('fulfilled_quantity', 'Fulfilled qty'),
            ]), 'fulfillment.stock_request.read', warehouseScope: ['warehouse_id'], branchScope: 'branch_id', ownColumn: 'requester_id',
                relationships: [R::many('lines', 'Lines', 'stock_request_lines', 'stock_request_id')], defaultSort: [['document_date', 'desc']]),

            new D('stock_request_lines', 'Stock request lines', 'fulfillment', <<<'SQL'
                SELECT l.id, l.stock_request_id, r.document_number, r.status AS request_status, r.warehouse_id, w.code AS warehouse_code, r.branch_id, r.requester_id,
                       l.line_no, l.item_id, i.sku AS item_sku, i.name AS item_name, l.requested_base_quantity AS requested_quantity,
                       l.approved_base_quantity AS approved_quantity, l.fulfilled_base_quantity AS fulfilled_quantity, l.returned_base_quantity AS returned_quantity,
                       CASE WHEN l.approved_base_quantity > 0 THEN round(100.0 * l.fulfilled_base_quantity / l.approved_base_quantity, 2) END AS fill_rate_percent
                  FROM stock_request_lines l JOIN stock_requests r ON r.id = l.stock_request_id JOIN warehouses w ON w.id = r.warehouse_id JOIN items i ON i.id = l.item_id
                SQL, [
                F::uuid('id', 'Line ID'), F::uuid('stock_request_id', 'Request ID'), F::string('document_number', 'Request'), F::enum('request_status', 'Request status', StockRequestStatus::class),
                F::uuid('warehouse_id', 'Warehouse ID'), F::string('warehouse_code', 'Warehouse'), F::uuid('branch_id', 'Branch ID'), F::uuid('requester_id', 'Requester ID'),
                F::int('line_no', 'Line'), F::uuid('item_id', 'Item ID'), F::string('item_sku', 'SKU'), F::string('item_name', 'Item'), F::qty('requested_quantity', 'Requested'),
                F::qty('approved_quantity', 'Approved'), F::qty('fulfilled_quantity', 'Fulfilled'), F::qty('returned_quantity', 'Returned'), F::decimal('fill_rate_percent', 'Fill rate %'),
            ], 'fulfillment.stock_request.read', warehouseScope: ['warehouse_id'], branchScope: 'branch_id', ownColumn: 'requester_id'),

            new D('stock_issues', 'Stock issues', 'fulfillment', <<<'SQL'
                SELECT s.id, s.document_number, s.status, s.warehouse_id, w.code AS warehouse_code, s.branch_id, s.document_date, s.stock_request_id,
                       r.document_number AS request_number, s.is_direct, s.is_production_issue, p.name AS recipient_name, s.recipient_name AS received_by, s.posted_at,
                       (SELECT COALESCE(sum(l.base_quantity * COALESCE(l.unit_cost_snapshot, 0)), 0) FROM stock_issue_lines l WHERE l.stock_issue_id = s.id) AS issued_value
                  FROM stock_issues s JOIN warehouses w ON w.id = s.warehouse_id LEFT JOIN stock_requests r ON r.id = s.stock_request_id
                  LEFT JOIN partners p ON p.id = s.recipient_partner_id
                SQL, array_merge([F::uuid('id', 'Issue ID'), F::string('document_number', 'Issue'), F::enum('status', 'Status', StockIssueStatus::class)], $wh(), [
                F::uuid('stock_request_id', 'Request ID'), F::string('request_number', 'Request'), F::bool('is_direct', 'Direct issue'), F::bool('is_production_issue', 'To production'),
                F::string('recipient_name', 'Recipient'), F::personal('received_by', 'Received by'), F::datetime('posted_at', 'Posted'), F::money('issued_value', 'Issued value'),
            ]), 'fulfillment.stock_issue.read', warehouseScope: ['warehouse_id'], branchScope: 'branch_id', defaultSort: [['document_date', 'desc']]),

            new D('stock_issue_lines', 'Stock issue lines', 'fulfillment', <<<'SQL'
                SELECT l.id, l.stock_issue_id, s.document_number, s.status AS issue_status, s.warehouse_id, w.code AS warehouse_code, s.branch_id, s.document_date,
                       l.line_no, l.item_id, i.sku AS item_sku, i.name AS item_name, sl.full_path AS location_path, lt.lot_number, l.base_quantity AS quantity,
                       l.returned_base_quantity AS returned_quantity, l.unit_cost_snapshot AS unit_cost, round(l.base_quantity * COALESCE(l.unit_cost_snapshot, 0), 4) AS value
                  FROM stock_issue_lines l JOIN stock_issues s ON s.id = l.stock_issue_id JOIN warehouses w ON w.id = s.warehouse_id JOIN items i ON i.id = l.item_id
                  JOIN storage_locations sl ON sl.id = l.storage_location_id LEFT JOIN inventory_lots lt ON lt.id = l.inventory_lot_id
                SQL, [
                F::uuid('id', 'Line ID'), F::uuid('stock_issue_id', 'Issue ID'), F::string('document_number', 'Issue'), F::enum('issue_status', 'Issue status', StockIssueStatus::class),
                F::uuid('warehouse_id', 'Warehouse ID'), F::string('warehouse_code', 'Warehouse'), F::uuid('branch_id', 'Branch ID'), F::date('document_date', 'Date'),
                F::int('line_no', 'Line'), F::uuid('item_id', 'Item ID'), F::string('item_sku', 'SKU'), F::string('item_name', 'Item'), F::string('location_path', 'Picked from'),
                F::string('lot_number', 'Lot'), F::qty('quantity', 'Quantity'), F::qty('returned_quantity', 'Returned'), F::money('unit_cost', 'Unit cost'), F::money('value', 'Value'),
            ], 'fulfillment.stock_issue.read', warehouseScope: ['warehouse_id'], branchScope: 'branch_id', relationships: [R::to('item', 'Item', 'items', 'item_id')]),

            new D('internal_returns', 'Internal returns', 'fulfillment', <<<'SQL'
                SELECT r.id, r.document_number, r.status, r.warehouse_id, w.code AS warehouse_code, r.branch_id, r.document_date, r.stock_issue_id,
                       s.document_number AS issue_number, r.reason, r.posted_at
                  FROM internal_returns r JOIN warehouses w ON w.id = r.warehouse_id JOIN stock_issues s ON s.id = r.stock_issue_id
                SQL, array_merge([F::uuid('id', 'Return ID'), F::string('document_number', 'Return'), F::enum('status', 'Status', InternalReturnStatus::class)], $wh(), [
                F::uuid('stock_issue_id', 'Issue ID'), F::string('issue_number', 'Issue'), F::string('reason', 'Reason'), F::datetime('posted_at', 'Posted'),
            ]), 'fulfillment.internal_return.read', warehouseScope: ['warehouse_id'], branchScope: 'branch_id'),

            new D('sales_orders', 'Sales orders', 'fulfillment', <<<'SQL'
                SELECT o.id, o.document_number, o.status, o.channel, o.warehouse_id, w.code AS warehouse_code, o.branch_id, o.document_date, o.customer_partner_id,
                       c.name AS customer_name, o.external_source, o.external_order_id, o.currency, o.total, o.confirmed_at, o.created_at,
                       (SELECT COALESCE(sum(l.base_quantity), 0) FROM sales_order_lines l WHERE l.sales_order_id = o.id) AS ordered_quantity,
                       (SELECT COALESCE(sum(l.delivered_base_quantity), 0) FROM sales_order_lines l WHERE l.sales_order_id = o.id) AS delivered_quantity
                  FROM sales_orders o JOIN warehouses w ON w.id = o.warehouse_id JOIN partners c ON c.id = o.customer_partner_id
                SQL, array_merge([F::uuid('id', 'Order ID'), F::string('document_number', 'Order'), F::enum('status', 'Status', SalesOrderStatus::class),
                F::enum('channel', 'Channel', SalesChannel::class)], $wh(), [
                    F::uuid('customer_partner_id', 'Customer ID'), F::string('customer_name', 'Customer'), F::string('external_source', 'External source'),
                    F::string('external_order_id', 'External order'), F::string('currency', 'Currency'), F::money('total', 'Order total', C::OPERATIONAL),
                    F::datetime('confirmed_at', 'Confirmed'), F::datetime('created_at', 'Created'), F::qty('ordered_quantity', 'Ordered qty'), F::qty('delivered_quantity', 'Delivered qty'),
                ]), 'fulfillment.sales_order.read', warehouseScope: ['warehouse_id'], branchScope: 'branch_id',
                relationships: [R::many('lines', 'Lines', 'sales_order_lines', 'sales_order_id'), R::to('customer', 'Customer', 'partners', 'customer_partner_id')],
                defaultSort: [['document_date', 'desc']]),

            new D('sales_order_lines', 'Sales order lines', 'fulfillment', <<<'SQL'
                SELECT l.id, l.sales_order_id, o.document_number, o.status AS order_status, o.channel, o.warehouse_id, w.code AS warehouse_code, o.branch_id, o.document_date,
                       l.line_no, l.item_id, i.sku AS item_sku, i.name AS item_name, l.base_quantity AS quantity, l.delivered_base_quantity AS delivered_quantity,
                       l.returned_base_quantity AS returned_quantity, l.unit_price, l.line_total
                  FROM sales_order_lines l JOIN sales_orders o ON o.id = l.sales_order_id JOIN warehouses w ON w.id = o.warehouse_id JOIN items i ON i.id = l.item_id
                SQL, [
                F::uuid('id', 'Line ID'), F::uuid('sales_order_id', 'Order ID'), F::string('document_number', 'Order'), F::enum('order_status', 'Order status', SalesOrderStatus::class),
                F::enum('channel', 'Channel', SalesChannel::class), F::uuid('warehouse_id', 'Warehouse ID'), F::string('warehouse_code', 'Warehouse'), F::uuid('branch_id', 'Branch ID'),
                F::date('document_date', 'Date'), F::int('line_no', 'Line'), F::uuid('item_id', 'Item ID'), F::string('item_sku', 'SKU'), F::string('item_name', 'Item'),
                F::qty('quantity', 'Ordered'), F::qty('delivered_quantity', 'Delivered'), F::qty('returned_quantity', 'Returned'),
                F::money('unit_price', 'Unit price', C::OPERATIONAL), F::money('line_total', 'Line total', C::OPERATIONAL),
            ], 'fulfillment.sales_order.read', warehouseScope: ['warehouse_id'], branchScope: 'branch_id', relationships: [R::to('item', 'Item', 'items', 'item_id')]),

            new D('shipments', 'Shipments', 'fulfillment', <<<'SQL'
                SELECT s.id, s.document_number, s.status, s.warehouse_id, w.code AS warehouse_code, w.branch_id, s.sales_order_id, o.document_number AS order_number,
                       o.external_order_id, c.name AS carrier_name, s.tracking_number, s.packed_at, s.handed_over_at, s.delivered_at,
                       TIMESTAMPDIFF(MICROSECOND, s.handed_over_at, s.delivered_at) / 3600000000.0 AS delivery_hours
                  FROM shipments s JOIN warehouses w ON w.id = s.warehouse_id JOIN sales_orders o ON o.id = s.sales_order_id LEFT JOIN partners c ON c.id = s.carrier_partner_id
                SQL, [
                F::uuid('id', 'Shipment ID'), F::string('document_number', 'Shipment'), F::enum('status', 'Status', ShipmentStatus::class), F::uuid('warehouse_id', 'Warehouse ID'),
                F::string('warehouse_code', 'Warehouse'), F::uuid('branch_id', 'Branch ID'), F::uuid('sales_order_id', 'Order ID'), F::string('order_number', 'Order'),
                F::string('external_order_id', 'External order'), F::string('carrier_name', 'Carrier'), F::string('tracking_number', 'Tracking'), F::datetime('packed_at', 'Packed'),
                F::datetime('handed_over_at', 'Handed over'), F::datetime('delivered_at', 'Delivered'), F::decimal('delivery_hours', 'Delivery time (h)'),
            ], 'fulfillment.shipment.read', warehouseScope: ['warehouse_id'], branchScope: 'branch_id', defaultSort: [['packed_at', 'desc']]),

            new D('customer_returns', 'Customer returns (RMA)', 'fulfillment', <<<'SQL'
                SELECT r.id, r.document_number, r.status, r.warehouse_id, w.code AS warehouse_code, r.branch_id, r.document_date, r.sales_order_id,
                       o.document_number AS order_number, c.name AS customer_name, r.reason, r.refund_reference, r.posted_at,
                       (SELECT COALESCE(sum(l.base_quantity), 0) FROM customer_return_lines l WHERE l.customer_return_id = r.id) AS returned_quantity
                  FROM customer_returns r JOIN warehouses w ON w.id = r.warehouse_id JOIN sales_orders o ON o.id = r.sales_order_id JOIN partners c ON c.id = r.customer_partner_id
                SQL, array_merge([F::uuid('id', 'Return ID'), F::string('document_number', 'RMA'), F::enum('status', 'Status', CustomerReturnStatus::class)], $wh(), [
                F::uuid('sales_order_id', 'Order ID'), F::string('order_number', 'Order'), F::string('customer_name', 'Customer'), F::string('reason', 'Reason'),
                F::string('refund_reference', 'Refund reference'), F::datetime('posted_at', 'Posted'), F::qty('returned_quantity', 'Returned qty'),
            ]), 'fulfillment.customer_return.read', warehouseScope: ['warehouse_id'], branchScope: 'branch_id', feature: 'feature.customer_returns'),
        ];
    }
}
