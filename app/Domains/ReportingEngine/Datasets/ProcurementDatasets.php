<?php

declare(strict_types=1);

namespace App\Domains\ReportingEngine\Datasets;

use App\Domains\Inventory\Enums\QcOutcome;
use App\Domains\Inventory\Enums\StockCondition;
use App\Domains\Organization\Enums\DocumentType;
use App\Domains\Procurement\Enums\PurchaseOrderStatus;
use App\Domains\Procurement\Enums\PurchaseRequestStatus;
use App\Domains\Procurement\Enums\SupplierReturnStatus;
use App\Domains\Receiving\Enums\GoodsReceiptStatus;
use App\Domains\Receiving\Enums\ReceivingCondition;
use App\Domains\ReportingEngine\Catalog\DatasetDefinition as D;
use App\Domains\ReportingEngine\Catalog\DatasetField as F;
use App\Domains\ReportingEngine\Catalog\DatasetRelationship as R;
use App\Domains\ReportingEngine\Catalog\ProvidesDatasets;
use App\Support\Enums\Priority;

/** Purchasing and receiving (Chapter 08 §8.2–8.3). */
final class ProcurementDatasets implements ProvidesDatasets
{
    public static function datasets(): array
    {
        $doc = fn () => [F::uuid('warehouse_id', 'Warehouse ID'), F::string('warehouse_code', 'Warehouse'), F::uuid('branch_id', 'Branch ID'),
            F::date('document_date', 'Date'), F::enum('priority', 'Priority', Priority::class), F::string('reference', 'Reference'),
            F::datetime('submitted_at', 'Submitted'), F::datetime('approved_at', 'Approved'), F::datetime('created_at', 'Created')];

        return [
            new D('purchase_requests', 'Purchase requests', 'procurement', <<<'SQL'
                SELECT r.id, r.document_number, r.status, r.warehouse_id, w.code AS warehouse_code, r.branch_id, r.document_date, r.priority, r.reference,
                       r.submitted_at, r.approved_at, r.created_at, r.required_by, r.estimated_total, r.currency, r.created_by,
                       (SELECT count(*) FROM purchase_request_lines x WHERE x.purchase_request_id = r.id) AS line_count
                  FROM purchase_requests r JOIN warehouses w ON w.id = r.warehouse_id
                SQL, array_merge([F::uuid('id', 'Request ID'), F::string('document_number', 'Request'), F::enum('status', 'Status', PurchaseRequestStatus::class)], $doc(), [
                F::date('required_by', 'Required by'), F::money('estimated_total', 'Estimated total'), F::string('currency', 'Currency'), F::int('line_count', 'Lines'),
                F::uuid('created_by', 'Created by'),
            ]), 'procurement.purchase_request.read', warehouseScope: ['warehouse_id'], branchScope: 'branch_id', ownColumn: 'created_by', defaultSort: [['document_date', 'desc']]),

            new D('purchase_orders', 'Purchase orders', 'procurement', <<<'SQL'
                SELECT o.id, o.document_number, o.status, o.warehouse_id, w.code AS warehouse_code, o.branch_id, o.document_date, o.priority, o.reference,
                       o.submitted_at, o.approved_at, o.created_at, o.supplier_id, s.code AS supplier_code, s.name AS supplier_name, o.expected_delivery_date,
                       o.currency, o.subtotal, o.charges_total, o.total, o.revision, o.sent_at, o.closed_at,
                       CASE WHEN o.expected_delivery_date < CURRENT_DATE AND o.status IN ('approved', 'sent', 'partially_received') THEN true ELSE false END AS is_overdue
                  FROM purchase_orders o JOIN warehouses w ON w.id = o.warehouse_id JOIN partners s ON s.id = o.supplier_id
                SQL, array_merge([F::uuid('id', 'Order ID'), F::string('document_number', 'PO'), F::enum('status', 'Status', PurchaseOrderStatus::class)], $doc(), [
                F::uuid('supplier_id', 'Supplier ID'), F::string('supplier_code', 'Supplier code'), F::string('supplier_name', 'Supplier'),
                F::date('expected_delivery_date', 'Expected delivery'), F::string('currency', 'Currency'), F::money('subtotal', 'Subtotal'),
                F::money('charges_total', 'Charges'), F::money('total', 'Total'), F::int('revision', 'Revision'), F::datetime('sent_at', 'Sent'),
                F::datetime('closed_at', 'Closed'), F::bool('is_overdue', 'Overdue'),
            ]), 'procurement.purchase_order.read', warehouseScope: ['warehouse_id'], branchScope: 'branch_id',
                relationships: [R::to('supplier', 'Supplier', 'partners', 'supplier_id'), R::many('lines', 'Lines', 'purchase_order_lines', 'purchase_order_id')],
                defaultSort: [['document_date', 'desc']]),

            new D('purchase_order_lines', 'Purchase order lines (with outstanding)', 'procurement', <<<'SQL'
                SELECT l.id, l.purchase_order_id, o.document_number, o.status AS order_status, o.warehouse_id, w.code AS warehouse_code, o.branch_id,
                       o.supplier_id, s.name AS supplier_name, l.line_no, l.item_id, i.sku AS item_sku, i.name AS item_name, l.base_quantity AS ordered_quantity,
                       l.received_base_quantity AS received_quantity, l.rejected_base_quantity AS rejected_quantity, l.cancelled_base_quantity AS cancelled_quantity,
                       GREATEST(l.base_quantity - l.received_base_quantity - l.cancelled_base_quantity, 0) AS outstanding_quantity,
                       l.unit_price, l.discount_percent, l.tax_percent, l.line_total, COALESCE(l.expected_date, o.expected_delivery_date) AS expected_date
                  FROM purchase_order_lines l JOIN purchase_orders o ON o.id = l.purchase_order_id JOIN warehouses w ON w.id = o.warehouse_id
                  JOIN partners s ON s.id = o.supplier_id JOIN items i ON i.id = l.item_id
                SQL, [
                F::uuid('id', 'Line ID'), F::uuid('purchase_order_id', 'Order ID'), F::string('document_number', 'PO'), F::enum('order_status', 'PO status', PurchaseOrderStatus::class),
                F::uuid('warehouse_id', 'Warehouse ID'), F::string('warehouse_code', 'Warehouse'), F::uuid('branch_id', 'Branch ID'), F::uuid('supplier_id', 'Supplier ID'),
                F::string('supplier_name', 'Supplier'), F::int('line_no', 'Line'), F::uuid('item_id', 'Item ID'), F::string('item_sku', 'SKU'), F::string('item_name', 'Item'),
                F::qty('ordered_quantity', 'Ordered'), F::qty('received_quantity', 'Received'), F::qty('rejected_quantity', 'Rejected'), F::qty('cancelled_quantity', 'Cancelled'),
                F::qty('outstanding_quantity', 'Outstanding'), F::money('unit_price', 'Unit price'), F::decimal('discount_percent', 'Discount %'),
                F::decimal('tax_percent', 'Tax %'), F::money('line_total', 'Line total'), F::date('expected_date', 'Expected'),
            ], 'procurement.purchase_order.read', warehouseScope: ['warehouse_id'], branchScope: 'branch_id',
                relationships: [R::to('order', 'Purchase order', 'purchase_orders', 'purchase_order_id'), R::to('item', 'Item', 'items', 'item_id')]),

            new D('goods_receipts', 'Goods receipts', 'receiving', <<<'SQL'
                SELECT g.id, g.document_number, g.status, g.warehouse_id, w.code AS warehouse_code, g.branch_id, g.document_date, g.priority, g.reference,
                       g.submitted_at, g.approved_at, g.created_at, g.purchase_order_id, o.document_number AS purchase_order_number, g.supplier_id,
                       s.name AS supplier_name, g.supplier_delivery_reference, g.received_at, g.posted_at, g.is_direct
                  FROM goods_receipts g JOIN warehouses w ON w.id = g.warehouse_id LEFT JOIN purchase_orders o ON o.id = g.purchase_order_id
                  LEFT JOIN partners s ON s.id = g.supplier_id
                SQL, array_merge([F::uuid('id', 'Receipt ID'), F::string('document_number', 'Receipt'), F::enum('status', 'Status', GoodsReceiptStatus::class)], $doc(), [
                F::uuid('purchase_order_id', 'PO ID'), F::string('purchase_order_number', 'PO'), F::uuid('supplier_id', 'Supplier ID'), F::string('supplier_name', 'Supplier'),
                F::string('supplier_delivery_reference', 'Delivery note'), F::datetime('received_at', 'Received at'), F::datetime('posted_at', 'Posted at'), F::bool('is_direct', 'Direct receipt'),
            ]), 'receiving.goods_receipt.read', warehouseScope: ['warehouse_id'], branchScope: 'branch_id',
                relationships: [R::many('lines', 'Lines', 'goods_receipt_lines', 'goods_receipt_id')], defaultSort: [['document_date', 'desc']]),

            new D('goods_receipt_lines', 'Goods receipt lines', 'receiving', <<<'SQL'
                SELECT l.id, l.goods_receipt_id, g.document_number, g.status AS receipt_status, g.warehouse_id, w.code AS warehouse_code, g.branch_id, g.document_date,
                       l.line_no, l.item_id, i.sku AS item_sku, i.name AS item_name, l.expected_quantity, l.received_quantity, l.accepted_base_quantity AS accepted_quantity,
                       l.rejected_base_quantity AS rejected_quantity, l.lot_number, l.expiry_date, l.receiving_condition, l.resulting_condition, l.unit_cost
                  FROM goods_receipt_lines l JOIN goods_receipts g ON g.id = l.goods_receipt_id JOIN warehouses w ON w.id = g.warehouse_id JOIN items i ON i.id = l.item_id
                SQL, [
                F::uuid('id', 'Line ID'), F::uuid('goods_receipt_id', 'Receipt ID'), F::string('document_number', 'Receipt'), F::enum('receipt_status', 'Receipt status', GoodsReceiptStatus::class),
                F::uuid('warehouse_id', 'Warehouse ID'), F::string('warehouse_code', 'Warehouse'), F::uuid('branch_id', 'Branch ID'), F::date('document_date', 'Date'),
                F::int('line_no', 'Line'), F::uuid('item_id', 'Item ID'), F::string('item_sku', 'SKU'), F::string('item_name', 'Item'), F::qty('expected_quantity', 'Expected'),
                F::qty('received_quantity', 'Received'), F::qty('accepted_quantity', 'Accepted (base)'), F::qty('rejected_quantity', 'Rejected (base)'),
                F::string('lot_number', 'Lot'), F::date('expiry_date', 'Expiry'), F::enum('receiving_condition', 'Received as', ReceivingCondition::class),
                F::enum('resulting_condition', 'Stocked as', StockCondition::class), F::money('unit_cost', 'Unit cost'),
            ], 'receiving.goods_receipt.read', warehouseScope: ['warehouse_id'], branchScope: 'branch_id',
                relationships: [R::to('receipt', 'Receipt', 'goods_receipts', 'goods_receipt_id'), R::to('item', 'Item', 'items', 'item_id')]),

            new D('quality_checks', 'Quality checks', 'receiving', <<<'SQL'
                SELECT q.id, q.document_type, q.document_id, q.item_id, i.sku AS item_sku, i.name AS item_name, l.lot_number, q.warehouse_id, w.code AS warehouse_code,
                       w.branch_id, q.outcome, q.inspected_quantity, q.passed_quantity, q.failed_quantity, q.inspected_at
                  FROM quality_checks q JOIN items i ON i.id = q.item_id LEFT JOIN inventory_lots l ON l.id = q.inventory_lot_id LEFT JOIN warehouses w ON w.id = q.warehouse_id
                SQL, [
                F::uuid('id', 'Check ID'), F::enum('document_type', 'Document type', DocumentType::class), F::uuid('document_id', 'Document ID'), F::uuid('item_id', 'Item ID'),
                F::string('item_sku', 'SKU'), F::string('item_name', 'Item'), F::string('lot_number', 'Lot'), F::uuid('warehouse_id', 'Warehouse ID'),
                F::string('warehouse_code', 'Warehouse'), F::uuid('branch_id', 'Branch ID'), F::enum('outcome', 'Outcome', QcOutcome::class),
                F::qty('inspected_quantity', 'Inspected'), F::qty('passed_quantity', 'Passed'), F::qty('failed_quantity', 'Failed'), F::datetime('inspected_at', 'Inspected at'),
            ], 'receiving.goods_receipt.read', warehouseScope: ['warehouse_id'], branchScope: 'branch_id'),

            new D('supplier_returns', 'Supplier returns', 'procurement', <<<'SQL'
                SELECT r.id, r.document_number, r.status, r.warehouse_id, w.code AS warehouse_code, r.branch_id, r.document_date, r.priority, r.reference,
                       r.submitted_at, r.approved_at, r.created_at, r.supplier_id, s.name AS supplier_name, r.goods_receipt_id, r.reason, r.posted_at
                  FROM supplier_returns r JOIN warehouses w ON w.id = r.warehouse_id JOIN partners s ON s.id = r.supplier_id
                SQL, array_merge([F::uuid('id', 'Return ID'), F::string('document_number', 'Return'), F::enum('status', 'Status', SupplierReturnStatus::class)], $doc(), [
                F::uuid('supplier_id', 'Supplier ID'), F::string('supplier_name', 'Supplier'), F::uuid('goods_receipt_id', 'Receipt ID'), F::string('reason', 'Reason'),
                F::datetime('posted_at', 'Posted'),
            ]), 'procurement.supplier_return.read', warehouseScope: ['warehouse_id'], branchScope: 'branch_id'),
        ];
    }
}
