<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Domains\Billing\Enums as Bill;
use App\Domains\CustomerPortal\Enums as Cp;
use App\Domains\Fulfillment\Enums as Ful;
use App\Domains\IdentityAccess\Enums\DocumentAction;
use App\Domains\Inventory\Enums as Inv;
use App\Domains\Organization\Enums\DocumentType;
use App\Domains\Planning\Enums as Plan;
use App\Domains\Procurement\Enums as Pro;
use App\Domains\Receiving\Enums as Rec;
use App\Domains\Sales\Enums as Sales;
use App\Domains\Stocktaking\Enums as Stk;
use App\Domains\Transfers\Enums as Tr;

/**
 * How the operational documents are worked (Chapter 08, Chapter 12 §12.6): the workflow hubs of the
 * main navigation, and per document its happy path, the statuses in which each record action applies
 * and the queues of documents waiting for someone's action. Descriptive only — the services still
 * enforce every transition, permission, scope and SoD rule; WorkflowCatalogTest pins this table to the
 * real routes, enums and permissions.
 *
 * Queue fields: statuses waiting; `permission` of the action awaited (counts are scoped to where the
 * person holds it); `kind` approve|act|watch; optional `scope` warehouse columns (default: the
 * resource's), `scope_config` (columns chosen by a configuration value), `mine` (only rows whose
 * column is the person), `own` (the resource's own-record scope), `sod` (hide documents the person
 * may not take this action on), `hubs` (default: the document's hub; [] = no hub, link to the list).
 */
final class WorkflowCatalog
{
    /** Workflow hubs, in navigation order. Start actions are links gated by any of their permissions. */
    public const HUBS = [
        'receiving' => ['label' => 'Receive', 'icon' => 'inbox', 'description' => 'Goods arriving from suppliers: receive, inspect, post and put away.',
            'resources' => ['goods-receipts', 'putaway-tasks', 'purchase-orders', 'asns', 'supplier-returns'],
            'start' => [
                ['label' => 'Receive goods', 'href' => '/tasks/receiving', 'icon' => 'inbox', 'permission' => ['receiving.goods_receipt.create']],
                ['label' => 'Put away', 'href' => '/tasks/putaway', 'icon' => 'boxes', 'permission' => ['receiving.putaway.post']],
                ['label' => 'Return to supplier', 'href' => '/r/supplier-returns/new', 'icon' => 'refresh', 'permission' => ['procurement.supplier_return.create']],
                ['label' => 'Receive from production', 'href' => '/r/movements/new?via=production-receipt', 'icon' => 'factory', 'permission' => ['fulfillment.production.receive']],
            ]],
        'transfers' => ['label' => 'Transfers & moves', 'icon' => 'truck', 'description' => 'Stock moving between branches, warehouses and bins.',
            'resources' => ['transfers', 'transfer-discrepancies', 'allocation-plans', 'relocations', 'condition-changes'],
            'start' => [
                ['label' => 'New transfer', 'href' => '/r/transfers/new', 'icon' => 'truck', 'permission' => ['transfers.transfer.create']],
                ['label' => 'Move within a warehouse', 'href' => '/r/relocations/new', 'icon' => 'route', 'permission' => ['inventory.relocation.create']],
                ['label' => 'Change condition', 'href' => '/r/condition-changes/new', 'icon' => 'shield', 'permission' => ['inventory.condition.change']],
                ['label' => 'New allocation plan', 'href' => '/r/allocation-plans/new', 'icon' => 'chart', 'permission' => ['planning.allocation_plan.create']],
            ]],
        'requests' => ['label' => 'Requests & issues', 'icon' => 'clipboard', 'description' => 'Stock requested by departments and branches, issued from the store.',
            'resources' => ['stock-requests', 'stock-issues', 'pick-lists', 'internal-returns'],
            'start' => [
                ['label' => 'Request stock', 'href' => '/r/stock-requests/new', 'icon' => 'clipboard',
                    'permission' => ['fulfillment.stock_request.create', 'fulfillment.stock_request.create_own']],
                ['label' => 'Issue stock', 'href' => '/r/stock-issues/new', 'icon' => 'package', 'permission' => ['fulfillment.stock_issue.create']],
                ['label' => 'Issue to production', 'href' => '/r/stock-issues/new?via=production', 'icon' => 'factory', 'permission' => ['fulfillment.production.issue']],
            ]],
        'sales' => ['label' => 'Sales & delivery', 'icon' => 'bag', 'description' => 'Customer orders from confirmation to delivery, and returns.',
            'resources' => ['sales-orders', 'pick-lists', 'shipments', 'customer-returns'],
            'start' => [
                ['label' => 'New sales order', 'href' => '/r/sales-orders/new', 'icon' => 'bag', 'permission' => ['fulfillment.sales_order.create']],
                ['label' => 'Plan a pick wave', 'href' => '/r/pick-lists/new?via=wave', 'icon' => 'layers', 'permission' => ['fulfillment.pick_wave.manage']],
            ]],
        'customers' => ['label' => 'Customers & billing', 'icon' => 'users', 'description' => 'Quotations, registrations, invoices and payments with customers.',
            'resources' => ['quotations', 'customer-registrations', 'payment-confirmations', 'invoices', 'payments', 'delivery-acknowledgements', 'customer-invitations'],
            'start' => [
                ['label' => 'Invite a customer', 'href' => '/r/customer-invitations/new', 'icon' => 'mail', 'permission' => ['portal.invitation.create']],
                ['label' => 'Record a payment', 'href' => '/r/payments/new', 'icon' => 'coins', 'permission' => ['billing.payment.record']],
            ]],
        'counting' => ['label' => 'Counting', 'icon' => 'list-check', 'description' => 'Stocktakes, adjustments and write-offs.',
            'resources' => ['stocktakes', 'stock-adjustments', 'scrap-documents'],
            'start' => [
                ['label' => 'New stocktake', 'href' => '/r/stocktakes/new', 'icon' => 'list-check', 'permission' => ['stocktaking.stocktake.create']],
                ['label' => 'New adjustment', 'href' => '/r/stock-adjustments/new', 'icon' => 'sliders', 'permission' => ['stocktaking.adjustment.create']],
                ['label' => 'Write off stock', 'href' => '/r/scrap-documents/new', 'icon' => 'x', 'permission' => ['inventory.scrap.create']],
            ]],
        'purchasing' => ['label' => 'Purchasing', 'icon' => 'cart', 'description' => 'What to reorder, purchase requests and purchase orders.',
            'resources' => ['purchase-requests', 'purchase-orders'],
            'start' => [
                ['label' => 'What to reorder', 'href' => '/planning/replenishment', 'icon' => 'refresh', 'permission' => ['planning.replenishment.read']],
                ['label' => 'New purchase request', 'href' => '/r/purchase-requests/new', 'icon' => 'clipboard', 'permission' => ['procurement.purchase_request.create']],
                ['label' => 'New purchase order', 'href' => '/r/purchase-orders/new', 'icon' => 'cart', 'permission' => ['procurement.purchase_order.create']],
            ]],
    ];

    /**
     * What usually comes next once a record is in a given state: the hand-off to the next document of
     * the chain (an approved purchase request becomes a purchase order, a posted receipt is put away) or
     * the next set-up step of master data (a new warehouse gets storage locations). Shown on the record
     * page to people holding `permission`; `when` limits a link to records whose field holds one of the
     * values (list fields: any of them). `{field}` in `href` is filled from the record; `prefill[…]`
     * pre-fills the form it opens. Navigation only — the target page and the API check everything again.
     *
     * @var array<string, array<int, array{label: string, href: string, icon: string, permission: string, when?: array<string, array<int, string>>}>>
     */
    public const NEXT = [
        'purchase-requests' => [
            ['label' => 'Create the purchase order', 'href' => '/r/purchase-orders/new?prefill[purchase_request_id]={id}', 'icon' => 'cart',
                'permission' => 'procurement.purchase_order.create', 'when' => ['status' => ['approved', 'partially_ordered']]],
        ],
        'purchase-orders' => [
            ['label' => 'Receive the goods', 'href' => '/tasks/receiving/{id}', 'icon' => 'inbox', 'permission' => 'receiving.goods_receipt.create',
                'when' => ['status' => ['sent', 'partially_received']]],
        ],
        'goods-receipts' => [
            ['label' => 'Put the goods away', 'href' => '/tasks/putaway', 'icon' => 'boxes', 'permission' => 'receiving.putaway.post', 'when' => ['status' => ['posted']]],
        ],
        'putaway-tasks' => [
            ['label' => 'Put away the next goods', 'href' => '/tasks/putaway', 'icon' => 'boxes', 'permission' => 'receiving.putaway.post', 'when' => ['status' => ['posted']]],
        ],
        'transfers' => [
            ['label' => 'Resolve the discrepancies', 'href' => '/r/transfer-discrepancies?filter[status]=open,investigating', 'icon' => 'alert',
                'permission' => 'transfers.discrepancy.resolve', 'when' => ['status' => ['discrepancy_review']]],
        ],
        'stock-requests' => [
            ['label' => 'Issue the stock', 'href' => '/r/stock-issues/new?prefill[stock_request_id]={id}', 'icon' => 'package',
                'permission' => 'fulfillment.stock_issue.create', 'when' => ['status' => ['approved', 'partially_fulfilled']]],
        ],
        'sales-orders' => [
            ['label' => 'Create a pick list', 'href' => '/r/pick-lists/new?prefill[type]=sales_order&prefill[source_id]={id}', 'icon' => 'list-check',
                'permission' => 'fulfillment.pick_list.assign', 'when' => ['status' => ['confirmed', 'reserved']]],
        ],
        'invoices' => [
            ['label' => 'Record a payment', 'href' => '/r/payments/new?prefill[customer_partner_id]={customer_partner_id}', 'icon' => 'coins',
                'permission' => 'billing.payment.record', 'when' => ['status' => ['issued', 'partially_paid']]],
        ],
        'stocktakes' => [
            ['label' => 'Check the stock valuation', 'href' => '/valuation', 'icon' => 'coins', 'permission' => 'inventory.valuation.read', 'when' => ['status' => ['posted']]],
        ],
        'opening-balances' => [
            ['label' => 'Run a stock reconciliation', 'href' => '/r/reconciliation-runs/new', 'icon' => 'refresh', 'permission' => 'inventory.reconciliation.run',
                'when' => ['status' => ['posted']]],
        ],
        'movement-reversals' => [
            ['label' => 'Open the original movement', 'href' => '/r/movements/{original_movement_id}', 'icon' => 'history', 'permission' => 'inventory.movement.read'],
        ],
        'reconciliation-runs' => [
            ['label' => 'Review the differences', 'href' => '/r/reconciliation-findings?filter[status]=open,investigating', 'icon' => 'alert',
                'permission' => 'inventory.reconciliation.read', 'when' => ['status' => ['completed_with_findings']]],
        ],
        'reconciliation-findings' => [
            ['label' => 'Run another reconciliation', 'href' => '/r/reconciliation-runs/new', 'icon' => 'refresh', 'permission' => 'inventory.reconciliation.run',
                'when' => ['status' => ['repaired', 'accepted']]],
        ],
        'lots' => [
            ['label' => 'Trace this lot', 'href' => '/trace?lot={id}', 'icon' => 'route', 'permission' => 'inventory.trace.read'],
        ],
        'items' => [
            ['label' => 'See its stock', 'href' => '/stock/items/{id}', 'icon' => 'boxes', 'permission' => 'inventory.stock.read'],
            ['label' => 'Set a reorder rule', 'href' => '/r/reorder-rules/new?prefill[item_id]={id}', 'icon' => 'refresh', 'permission' => 'catalog.reorder_rule.manage'],
        ],
        'item-categories' => [
            ['label' => 'Add an item to this category', 'href' => '/r/items/new?prefill[category_id]={id}', 'icon' => 'package', 'permission' => 'catalog.item.create'],
        ],
        'branches' => [
            ['label' => 'Add a warehouse', 'href' => '/r/warehouses/new?prefill[branch_id]={id}', 'icon' => 'boxes', 'permission' => 'organization.warehouse.create'],
        ],
        'warehouses' => [
            ['label' => 'Add a storage location', 'href' => '/r/storage-locations/new?prefill[warehouse_id]={id}', 'icon' => 'route',
                'permission' => 'organization.storage_location.create'],
            ['label' => 'Add a put-away rule', 'href' => '/r/putaway-rules/new?prefill[warehouse_id]={id}', 'icon' => 'inbox', 'permission' => 'organization.putaway_rule.manage'],
            ['label' => 'Load opening balances', 'href' => '/r/opening-balances/new?prefill[warehouse_id]={id}', 'icon' => 'upload',
                'permission' => 'inventory.opening_balance.create'],
        ],
        'partners' => [
            ['label' => 'New purchase order', 'href' => '/r/purchase-orders/new?prefill[supplier_id]={id}', 'icon' => 'cart',
                'permission' => 'procurement.purchase_order.create', 'when' => ['partner_types' => ['supplier']]],
            ['label' => 'New sales order', 'href' => '/r/sales-orders/new?prefill[customer_partner_id]={id}', 'icon' => 'bag',
                'permission' => 'fulfillment.sales_order.create', 'when' => ['partner_types' => ['customer']]],
            ['label' => 'Invite to the customer portal', 'href' => '/r/customer-invitations/new?prefill[partner_id]={id}', 'icon' => 'mail',
                'permission' => 'portal.invitation.create', 'when' => ['partner_types' => ['customer']]],
        ],
        'persons' => [
            ['label' => 'Create a sign-in account', 'href' => '/r/accounts/new?prefill[person_id]={id}', 'icon' => 'user', 'permission' => 'identity.account.create'],
        ],
        'accounts' => [
            ['label' => 'Assign a role', 'href' => '/r/role-assignments/new?prefill[user_account_id]={id}', 'icon' => 'key', 'permission' => 'identity.assignment.create'],
        ],
        'roles' => [
            ['label' => 'Assign this role to someone', 'href' => '/r/role-assignments/new?prefill[role_id]={id}', 'icon' => 'link', 'permission' => 'identity.assignment.create'],
        ],
    ];

    /**
     * @return array<string, array{noun: string, hub: ?string, status_enum: class-string, document_type: ?DocumentType, steps: array<int, string>,
     *     editable: array<int, string>, actions: array<string, array<string, mixed>>, extra?: array<string, array<string, mixed>>, queues: array<string, array<string, mixed>>}>
     */
    public static function documents(): array
    {
        $approve = fn (string $label, array $statuses, string $permission, array $extra = []) => $extra + ['label' => $label, 'statuses' => $statuses,
            'permission' => $permission, 'kind' => 'approve', 'sod' => DocumentAction::APPROVE];
        $act = fn (string $label, array $statuses, string $permission, array $extra = []) => $extra + ['label' => $label, 'statuses' => $statuses,
            'permission' => $permission, 'kind' => 'act'];

        return [
            // ---- Purchasing ---------------------------------------------------------------------
            'purchase-requests' => ['noun' => 'purchase request', 'hub' => 'purchasing', 'status_enum' => Pro\PurchaseRequestStatus::class,
                'document_type' => DocumentType::PURCHASE_REQUEST, 'steps' => ['draft', 'submitted', 'approved', 'ordered'], 'editable' => ['draft'],
                'actions' => [
                    'submit' => ['from' => ['draft'], 'primary' => true],
                    'approve' => ['from' => ['submitted'], 'primary' => true],
                    'reject' => ['from' => ['submitted']],
                    'return' => ['from' => ['submitted'], 'label' => 'Return for correction'],
                    'cancel' => ['from' => ['draft', 'submitted', 'approved']],
                ],
                'queues' => [
                    'drafts' => $act('Purchase requests to submit', ['draft'], 'procurement.purchase_request.submit', ['mine' => 'created_by']),
                    'approve' => $approve('Purchase requests to approve', ['submitted'], 'procurement.purchase_request.approve'),
                    'order' => $act('Approved requests to order', ['approved', 'partially_ordered'], 'procurement.purchase_order.create'),
                ]],
            'purchase-orders' => ['noun' => 'purchase order', 'hub' => 'purchasing', 'status_enum' => Pro\PurchaseOrderStatus::class,
                'document_type' => DocumentType::PURCHASE_ORDER, 'steps' => ['draft', 'submitted', 'approved', 'sent', 'fully_received', 'closed'], 'editable' => ['draft'],
                'actions' => [
                    'submit' => ['from' => ['draft'], 'primary' => true],
                    'approve' => ['from' => ['submitted'], 'primary' => true],
                    'reject' => ['from' => ['submitted'], 'label' => 'Return for correction'],
                    'send' => ['from' => ['approved'], 'primary' => true, 'label' => 'Send to supplier'],
                    'revise' => ['from' => ['approved', 'sent']],
                    'asns' => ['from' => ['sent', 'partially_received'], 'label' => 'Add shipment notice'],
                    'close' => ['from' => ['partially_received', 'fully_received'], 'primary' => true],
                    'cancel' => ['from' => ['draft', 'submitted', 'approved', 'sent']],
                ],
                'queues' => [
                    'drafts' => $act('Purchase orders to submit', ['draft'], 'procurement.purchase_order.submit', ['mine' => 'created_by']),
                    'approve' => $approve('Purchase orders to approve', ['submitted'], 'procurement.purchase_order.approve'),
                    'send' => $act('Approved orders to send', ['approved'], 'procurement.purchase_order.send'),
                    'receive' => $act('Orders awaiting delivery', ['sent', 'partially_received'], 'receiving.goods_receipt.create', ['hubs' => ['receiving']]),
                    'close' => $act('Received orders to close', ['fully_received'], 'procurement.purchase_order.close'),
                ]],
            'supplier-returns' => ['noun' => 'supplier return', 'hub' => 'receiving', 'status_enum' => Pro\SupplierReturnStatus::class,
                'document_type' => DocumentType::SUPPLIER_RETURN, 'steps' => ['draft', 'pending_approval', 'approved', 'posted'], 'editable' => ['draft'],
                'actions' => [
                    'submit' => ['from' => ['draft'], 'primary' => true],
                    'approve' => ['from' => ['pending_approval'], 'primary' => true],
                    'post' => ['from' => ['approved'], 'primary' => true, 'label' => 'Post return'],
                    'cancel' => ['from' => ['draft', 'pending_approval', 'approved']],
                ],
                'queues' => [
                    'approve' => $approve('Supplier returns to approve', ['pending_approval'], 'procurement.supplier_return.approve'),
                    'post' => $act('Supplier returns to post', ['approved'], 'procurement.supplier_return.post'),
                ]],

            // ---- Receiving --------------------------------------------------------------------
            'goods-receipts' => ['noun' => 'goods receipt', 'hub' => 'receiving', 'status_enum' => Rec\GoodsReceiptStatus::class,
                'document_type' => DocumentType::GOODS_RECEIPT, 'steps' => ['draft', 'in_inspection', 'pending_approval', 'approved', 'posted'], 'editable' => ['draft'],
                'actions' => [
                    'submit' => ['from' => ['draft', 'in_inspection'], 'primary' => true],
                    'approve' => ['from' => ['pending_approval'], 'primary' => true],
                    'post' => ['from' => ['draft', 'in_inspection', 'approved'], 'primary' => true, 'label' => 'Post receipt'],
                    'cancel' => ['from' => ['draft', 'in_inspection', 'pending_approval', 'approved']],
                    'landed-costs' => ['from' => ['posted'], 'label' => 'Add landed costs'],
                ],
                'queues' => [
                    'finish' => $act('Receipts in progress', ['draft', 'in_inspection'], 'receiving.goods_receipt.submit'),
                    'approve' => $approve('Receipts to approve', ['pending_approval'], 'receiving.goods_receipt.approve'),
                    'post' => $act('Receipts to post', ['approved'], 'receiving.goods_receipt.post'),
                ]],
            'putaway-tasks' => ['noun' => 'put-away task', 'hub' => 'receiving', 'status_enum' => Rec\PutawayTaskStatus::class,
                'document_type' => null, 'steps' => ['open', 'posted'], 'editable' => [],
                'actions' => [
                    'post' => ['from' => ['open'], 'primary' => true, 'label' => 'Put away', 'task' => '/tasks/putaway'],
                    'assign' => ['from' => ['open']],
                ],
                'queues' => [
                    'open' => $act('Goods to put away', ['open'], 'receiving.putaway.post'),
                ]],
            'asns' => ['noun' => 'shipment notice', 'hub' => 'receiving', 'status_enum' => Pro\AsnStatus::class,
                'document_type' => DocumentType::ADVANCE_SHIPMENT_NOTICE, 'steps' => ['open', 'received'], 'editable' => [],
                'actions' => [
                    'receipt' => ['from' => ['open'], 'primary' => true, 'label' => 'Start receipt'],
                ],
                'queues' => [
                    'open' => $act('Shipment notices to receive', ['open'], 'receiving.goods_receipt.create'),
                ]],

            // ---- Transfers & moves ------------------------------------------------------------
            'transfers' => ['noun' => 'transfer', 'hub' => 'transfers', 'status_enum' => Tr\TransferStatus::class,
                'document_type' => DocumentType::TRANSFER_ORDER, 'steps' => ['draft', 'submitted', 'approved', 'in_transit', 'received', 'closed'], 'editable' => ['draft'],
                'actions' => [
                    'submit' => ['from' => ['draft'], 'primary' => true],
                    'approve' => ['from' => ['submitted'], 'primary' => true],
                    'reject' => ['from' => ['submitted'], 'label' => 'Return for correction'],
                    'pick-list' => ['from' => ['approved'], 'label' => 'Create pick list'],
                    'dispatch' => ['from' => ['approved', 'picking', 'partially_dispatched'], 'primary' => true, 'label' => 'Start dispatch',
                        'task' => '/tasks/transfers/{id}/dispatch'],
                    'receive' => ['from' => ['partially_dispatched', 'in_transit', 'partially_received'], 'primary' => true, 'label' => 'Start receiving',
                        'task' => '/tasks/transfers/{id}/receive'],
                    'close' => ['from' => ['received', 'discrepancy_review'], 'primary' => true],
                    'cancel' => ['from' => ['draft', 'submitted', 'approved', 'picking', 'partially_dispatched']],
                ],
                'queues' => [
                    'drafts' => $act('Transfers to submit', ['draft'], 'transfers.transfer.submit', ['mine' => 'created_by']),
                    'approve' => $approve('Transfers to approve', ['submitted'], 'transfers.transfer.approve', ['scope_config' => 'transfers.approve_side']),
                    'dispatch' => $act('Transfers to dispatch', ['approved', 'picking', 'partially_dispatched'], 'transfers.transfer.dispatch',
                        ['scope' => ['source_warehouse_id']]),
                    'receive' => $act('Transfers to receive', ['in_transit', 'partially_received'], 'transfers.transfer.receive',
                        ['scope' => ['destination_warehouse_id']]),
                    'close' => $act('Transfers to close', ['received', 'discrepancy_review'], 'transfers.transfer.close'),
                ]],
            'transfer-discrepancies' => ['noun' => 'transfer discrepancy', 'hub' => 'transfers', 'status_enum' => Tr\DiscrepancyStatus::class,
                'document_type' => null, 'steps' => ['open', 'investigating', 'resolved'], 'editable' => [],
                'actions' => [],
                'extra' => [
                    'investigate' => ['route' => 'api.v1.transfers.discrepancies.investigate', 'from' => ['open'],
                        'params' => ['transferId' => 'transfer_order_id', 'discrepancyId' => 'id']],
                    'resolve' => ['route' => 'api.v1.wf.transfers.discrepancies.resolve', 'from' => ['open', 'investigating'], 'primary' => true,
                        'params' => ['transferId' => 'transfer_order_id', 'discrepancyId' => 'id']],
                ],
                'queues' => [
                    'open' => $act('Transfer discrepancies to resolve', ['open', 'investigating'], 'transfers.discrepancy.resolve'),
                ]],
            'allocation-plans' => ['noun' => 'allocation plan', 'hub' => 'transfers', 'status_enum' => Plan\AllocationPlanStatus::class,
                'document_type' => DocumentType::ALLOCATION_PLAN, 'steps' => ['draft', 'pending_approval', 'approved', 'released'], 'editable' => ['draft'],
                'actions' => [
                    'suggest' => ['from' => ['draft'], 'label' => 'Suggest quantities'],
                    'submit' => ['from' => ['draft'], 'primary' => true],
                    'approve' => ['from' => ['pending_approval'], 'primary' => true],
                    'release' => ['from' => ['approved'], 'primary' => true, 'label' => 'Release transfers'],
                    'cancel' => ['from' => ['draft', 'pending_approval', 'approved']],
                ],
                'queues' => [
                    'approve' => $approve('Allocation plans to approve', ['pending_approval'], 'planning.allocation_plan.approve'),
                    'release' => $act('Allocation plans to release', ['approved'], 'planning.allocation_plan.release'),
                ]],
            'relocations' => ['noun' => 'relocation', 'hub' => 'transfers', 'status_enum' => Inv\RelocationStatus::class,
                'document_type' => DocumentType::RELOCATION, 'steps' => ['draft', 'posted'], 'editable' => ['draft'],
                'actions' => [
                    'post' => ['from' => ['draft'], 'primary' => true, 'label' => 'Post move'],
                    'cancel' => ['from' => ['draft']],
                ],
                'queues' => [
                    'drafts' => $act('Moves to post', ['draft'], 'inventory.relocation.post'),
                ]],
            'condition-changes' => ['noun' => 'condition change', 'hub' => 'transfers', 'status_enum' => Inv\ConditionChangeStatus::class,
                'document_type' => DocumentType::CONDITION_CHANGE, 'steps' => ['draft', 'posted'], 'editable' => ['draft'],
                'actions' => [
                    'post' => ['from' => ['draft', 'approved'], 'primary' => true, 'label' => 'Post change'],
                    'release' => ['from' => ['draft'], 'label' => 'Release from quarantine'],
                    'cancel' => ['from' => ['draft']],
                ],
                'queues' => [
                    'drafts' => $act('Condition changes to post', ['draft'], 'inventory.condition.change'),
                ]],

            // ---- Requests & issues ------------------------------------------------------------
            'stock-requests' => ['noun' => 'stock request', 'hub' => 'requests', 'status_enum' => Ful\StockRequestStatus::class,
                'document_type' => DocumentType::STOCK_REQUEST, 'steps' => ['draft', 'submitted', 'approved', 'fulfilled'], 'editable' => ['draft'],
                'actions' => [
                    'submit' => ['from' => ['draft'], 'primary' => true],
                    'approve' => ['from' => ['submitted'], 'primary' => true],
                    'reject' => ['from' => ['submitted']],
                    'cancel' => ['from' => ['draft', 'submitted', 'approved', 'partially_fulfilled']],
                ],
                'queues' => [
                    'mine' => ['label' => 'My open requests', 'statuses' => ['draft', 'submitted', 'approved', 'partially_fulfilled'],
                        'permission' => 'fulfillment.stock_request.read_own', 'kind' => 'watch', 'own' => true],
                    'approve' => $approve('Stock requests to approve', ['submitted'], 'fulfillment.stock_request.approve'),
                    'issue' => $act('Approved requests to issue', ['approved', 'partially_fulfilled'], 'fulfillment.stock_issue.create'),
                ]],
            'stock-issues' => ['noun' => 'stock issue', 'hub' => 'requests', 'status_enum' => Ful\StockIssueStatus::class,
                'document_type' => DocumentType::STOCK_ISSUE, 'steps' => ['draft', 'pending_approval', 'approved', 'posted'], 'editable' => ['draft'],
                'actions' => [
                    'submit' => ['from' => ['draft'], 'primary' => true],
                    'approve' => ['from' => ['pending_approval'], 'primary' => true],
                    'post' => ['from' => ['draft', 'approved'], 'primary' => true, 'label' => 'Post issue'],
                    'returns' => ['from' => ['posted'], 'label' => 'Return to store'],
                    'cancel' => ['from' => ['draft', 'pending_approval', 'approved']],
                ],
                'queues' => [
                    'drafts' => $act('Issues to submit', ['draft'], 'fulfillment.stock_issue.submit'),
                    'approve' => $approve('Issues to approve', ['pending_approval'], 'fulfillment.stock_issue.approve'),
                    'post' => $act('Issues to post', ['approved'], 'fulfillment.stock_issue.post'),
                ]],
            'internal-returns' => ['noun' => 'internal return', 'hub' => 'requests', 'status_enum' => Ful\InternalReturnStatus::class,
                'document_type' => DocumentType::INTERNAL_RETURN, 'steps' => ['draft', 'in_inspection', 'approved', 'posted'], 'editable' => [],
                'actions' => [
                    'inspect' => ['from' => ['draft'], 'primary' => true],
                    'approve' => ['from' => ['draft', 'in_inspection'], 'primary' => true],
                    'post' => ['from' => ['approved'], 'primary' => true, 'label' => 'Post return'],
                    'cancel' => ['from' => ['draft', 'in_inspection', 'approved']],
                ],
                'queues' => [
                    'inspect' => $act('Returns to inspect', ['draft'], 'fulfillment.internal_return.inspect'),
                    'approve' => $approve('Returns to approve', ['in_inspection'], 'fulfillment.internal_return.approve'),
                    'post' => $act('Returns to post', ['approved'], 'fulfillment.internal_return.post'),
                ]],
            'pick-lists' => ['noun' => 'pick list', 'hub' => 'requests', 'status_enum' => Ful\PickListStatus::class,
                'document_type' => DocumentType::PICK_LIST, 'steps' => ['open', 'in_progress', 'completed'], 'editable' => [],
                'actions' => [
                    'picks' => ['from' => ['open', 'in_progress'], 'primary' => true, 'label' => 'Start picking', 'task' => '/tasks/pick-lists/{id}/pick'],
                    'complete' => ['from' => ['in_progress']],
                ],
                'queues' => [
                    'pick' => $act('Pick lists to pick', ['open', 'in_progress'], 'fulfillment.pick_list.pick', ['hubs' => ['requests', 'sales']]),
                ]],

            // ---- Sales & delivery -------------------------------------------------------------
            'sales-orders' => ['noun' => 'sales order', 'hub' => 'sales', 'status_enum' => Ful\SalesOrderStatus::class,
                'document_type' => DocumentType::SALES_ORDER, 'steps' => ['draft', 'confirmed', 'picking', 'packed', 'delivered'], 'editable' => ['draft'],
                'actions' => [
                    'confirm' => ['from' => ['draft'], 'primary' => true],
                    'shipments' => ['from' => ['confirmed', 'reserved', 'picking'], 'primary' => true, 'label' => 'Pack shipment'],
                    'deliveries' => ['from' => ['confirmed', 'reserved', 'picking', 'packed', 'partially_delivered'], 'primary' => true, 'label' => 'Record delivery'],
                    'invoice' => ['from' => ['confirmed', 'reserved', 'picking', 'packed', 'partially_delivered', 'delivered'], 'label' => 'Create invoice'],
                    'returns' => ['from' => ['partially_delivered', 'delivered'], 'label' => 'Record customer return'],
                    'cancel' => ['from' => ['draft', 'confirmed', 'reserved', 'picking', 'packed']],
                ],
                'queues' => [
                    'confirm' => $act('Sales orders to confirm', ['draft'], 'fulfillment.sales_order.confirm'),
                    'pack' => $act('Orders to pack', ['confirmed', 'reserved', 'picking'], 'fulfillment.shipment.pack'),
                    'deliver' => $act('Orders to deliver', ['packed', 'partially_delivered'], 'fulfillment.sales_order.deliver'),
                ]],
            'shipments' => ['noun' => 'shipment', 'hub' => 'sales', 'status_enum' => Ful\ShipmentStatus::class,
                'document_type' => DocumentType::SHIPMENT, 'steps' => ['packed', 'handed_over', 'in_transit', 'delivered'], 'editable' => [],
                'actions' => [
                    'handover' => ['from' => ['packed'], 'primary' => true, 'label' => 'Hand over to carrier'],
                    'events' => ['from' => ['handed_over', 'in_transit'], 'primary' => true, 'label' => 'Update delivery status'],
                    'invoice' => ['from' => ['packed', 'handed_over', 'in_transit', 'delivered'], 'label' => 'Create invoice'],
                    'cancel' => ['from' => ['packed']],
                ],
                'queues' => [
                    'handover' => $act('Shipments to hand over', ['packed'], 'fulfillment.shipment.handover'),
                    'track' => ['label' => 'Shipments on the way', 'statuses' => ['handed_over', 'in_transit'], 'permission' => 'fulfillment.shipment.update_status',
                        'kind' => 'watch'],
                ]],
            'customer-returns' => ['noun' => 'customer return', 'hub' => 'sales', 'status_enum' => Ful\CustomerReturnStatus::class,
                'document_type' => DocumentType::CUSTOMER_RETURN, 'steps' => ['requested', 'authorized', 'received', 'inspected', 'posted'], 'editable' => [],
                'actions' => [
                    'authorize' => ['from' => ['requested'], 'primary' => true],
                    'reject' => ['from' => ['requested', 'inspected']],
                    'receive' => ['from' => ['authorized'], 'primary' => true],
                    'inspect' => ['from' => ['received'], 'primary' => true],
                    'post' => ['from' => ['received', 'inspected'], 'primary' => true, 'label' => 'Post return'],
                    'cancel' => ['from' => ['requested', 'authorized']],
                ],
                'queues' => [
                    'authorize' => $approve('Customer returns to authorize', ['requested'], 'fulfillment.customer_return.authorize', ['sod' => null]),
                    'receive' => $act('Customer returns to receive', ['authorized'], 'fulfillment.customer_return.receive'),
                    'inspect' => $act('Customer returns to inspect', ['received'], 'fulfillment.customer_return.inspect'),
                    'post' => $act('Customer returns to post', ['inspected'], 'fulfillment.customer_return.post'),
                ]],

            // ---- Customers & billing ----------------------------------------------------------
            'quotations' => ['noun' => 'quotation', 'hub' => 'customers', 'status_enum' => Sales\QuotationStatus::class,
                'document_type' => DocumentType::QUOTATION, 'steps' => ['requested', 'sent', 'accepted'], 'editable' => [],
                'actions' => [
                    'price' => ['from' => ['requested', 'revision_requested', 'expired'], 'primary' => true, 'label' => 'Price and send'],
                    'cancel' => ['from' => ['requested', 'sent', 'revision_requested']],
                ],
                'queues' => [
                    'price' => $act('Quotations to price', ['requested', 'revision_requested'], 'sales.quotation.price'),
                ]],
            'customer-registrations' => ['noun' => 'customer registration', 'hub' => 'customers', 'status_enum' => Cp\RegistrationStatus::class,
                'document_type' => null, 'steps' => ['pending_verification', 'pending_mfa', 'pending_approval', 'approved'], 'editable' => [],
                'actions' => [
                    'approve' => ['from' => ['pending_approval'], 'primary' => true],
                    'reject' => ['from' => ['pending_approval']],
                ],
                'queues' => [
                    'approve' => $approve('Customer registrations to approve', ['pending_approval'], 'portal.registration.approve', ['sod' => null]),
                ]],
            'payment-confirmations' => ['noun' => 'payment confirmation', 'hub' => 'customers', 'status_enum' => Bill\PaymentConfirmationStatus::class,
                'document_type' => DocumentType::PAYMENT_CONFIRMATION, 'steps' => ['submitted', 'verified'], 'editable' => [],
                'actions' => [
                    'verify' => ['from' => ['submitted', 'info_requested'], 'primary' => true],
                    'request-information' => ['from' => ['submitted']],
                    'reject' => ['from' => ['submitted', 'info_requested']],
                ],
                'queues' => [
                    'verify' => $approve('Payment confirmations to verify', ['submitted'], 'billing.payment_confirmation.review', ['sod' => null]),
                ]],
            'delivery-acknowledgements' => ['noun' => 'delivery issue', 'hub' => 'customers', 'status_enum' => Cp\AcknowledgementStatus::class,
                'document_type' => null, 'steps' => ['open', 'resolved'], 'editable' => [],
                'actions' => [
                    'resolve' => ['from' => ['open'], 'primary' => true],
                ],
                'queues' => [
                    'resolve' => $act('Delivery issues to resolve', ['open'], 'portal.acknowledgement.resolve'),
                ]],
            'invoices' => ['noun' => 'invoice', 'hub' => 'customers', 'status_enum' => Bill\InvoiceStatus::class,
                'document_type' => DocumentType::INVOICE, 'steps' => ['draft', 'issued', 'paid'], 'editable' => ['draft'],
                'actions' => [
                    'issue' => ['from' => ['draft'], 'primary' => true],
                    'void' => ['from' => ['draft', 'issued']],
                ],
                'queues' => [
                    'issue' => $act('Invoices to issue', ['draft'], 'billing.invoice.issue'),
                    'unpaid' => ['label' => 'Unpaid invoices', 'statuses' => ['issued', 'partially_paid'], 'permission' => 'billing.payment.record', 'kind' => 'watch'],
                ]],
            'payments' => ['noun' => 'payment', 'hub' => 'customers', 'status_enum' => Bill\PaymentStatus::class,
                'document_type' => DocumentType::PAYMENT, 'steps' => ['posted'], 'editable' => [],
                'actions' => [
                    'allocate' => ['from' => ['posted'], 'primary' => true, 'label' => 'Allocate to invoices'],
                    'void' => ['from' => ['posted']],
                ],
                'queues' => []],
            'customer-invitations' => ['noun' => 'customer invitation', 'hub' => 'customers', 'status_enum' => Cp\InvitationStatus::class,
                'document_type' => null, 'steps' => ['pending', 'accepted'], 'editable' => [],
                'actions' => [
                    'revoke' => ['from' => ['pending']],
                ],
                'queues' => []],

            // ---- Counting -----------------------------------------------------------------------
            'stocktakes' => ['noun' => 'stocktake', 'hub' => 'counting', 'status_enum' => Stk\StocktakeStatus::class,
                'document_type' => DocumentType::STOCKTAKE, 'steps' => ['draft', 'scheduled', 'counting', 'review', 'approved', 'posted'], 'editable' => ['draft'],
                'actions' => [
                    'schedule' => ['from' => ['draft'], 'primary' => true],
                    'start' => ['from' => ['draft', 'scheduled'], 'primary' => true, 'label' => 'Start counting'],
                    'counts' => ['from' => ['counting'], 'primary' => true, 'label' => 'Count', 'task' => '/tasks/stocktakes/{id}/count'],
                    'recounts' => ['from' => ['recount_required'], 'primary' => true, 'label' => 'Recount', 'task' => '/tasks/stocktakes/{id}/count'],
                    'finish-counting' => ['from' => ['counting', 'recount_required']],
                    'review' => ['from' => ['review'], 'primary' => true, 'label' => 'Accept counts'],
                    'recount' => ['from' => ['review'], 'label' => 'Ask for a recount'],
                    'approve' => ['from' => ['pending_approval'], 'primary' => true],
                    'post' => ['from' => ['approved'], 'primary' => true, 'label' => 'Post stocktake'],
                    'cancel' => ['from' => ['draft', 'scheduled', 'counting', 'recount_required', 'review', 'pending_approval']],
                ],
                'queues' => [
                    'start' => $act('Stocktakes to start', ['scheduled'], 'stocktaking.stocktake.schedule'),
                    'count' => $act('Stocktakes to count', ['counting', 'recount_required'], 'stocktaking.stocktake.count'),
                    'review' => $act('Counts to review', ['review'], 'stocktaking.stocktake.review'),
                    'approve' => $approve('Stocktakes to approve', ['pending_approval'], 'stocktaking.stocktake.approve'),
                    'post' => $act('Stocktakes to post', ['approved'], 'stocktaking.stocktake.post'),
                ]],
            'stock-adjustments' => ['noun' => 'stock adjustment', 'hub' => 'counting', 'status_enum' => Stk\StockAdjustmentStatus::class,
                'document_type' => DocumentType::STOCK_ADJUSTMENT, 'steps' => ['draft', 'pending_approval', 'approved', 'posted'], 'editable' => ['draft'],
                'actions' => [
                    'submit' => ['from' => ['draft'], 'primary' => true],
                    'approve' => ['from' => ['pending_approval'], 'primary' => true],
                    'reject' => ['from' => ['pending_approval']],
                    'post' => ['from' => ['approved'], 'primary' => true, 'label' => 'Post adjustment'],
                    'cancel' => ['from' => ['draft', 'pending_approval', 'approved']],
                ],
                'queues' => [
                    'drafts' => $act('Adjustments to submit', ['draft'], 'stocktaking.adjustment.submit', ['mine' => 'created_by']),
                    'approve' => $approve('Adjustments to approve', ['pending_approval'], 'stocktaking.adjustment.approve'),
                    'post' => $act('Adjustments to post', ['approved'], 'stocktaking.adjustment.post'),
                ]],
            'scrap-documents' => ['noun' => 'write-off', 'hub' => 'counting', 'status_enum' => Inv\ScrapStatus::class,
                'document_type' => DocumentType::SCRAP, 'steps' => ['draft', 'pending_approval', 'approved', 'posted'], 'editable' => ['draft'],
                'actions' => [
                    'submit' => ['from' => ['draft'], 'primary' => true],
                    'approve' => ['from' => ['pending_approval'], 'primary' => true],
                    'post' => ['from' => ['approved'], 'primary' => true, 'label' => 'Post write-off'],
                    'cancel' => ['from' => ['draft', 'pending_approval', 'approved']],
                ],
                'queues' => [
                    'approve' => $approve('Write-offs to approve', ['pending_approval'], 'inventory.scrap.approve'),
                    'post' => $act('Write-offs to post', ['approved'], 'inventory.scrap.post'),
                ]],

            // ---- Go-live data (Administration → Data) ----------------------------------------
            'opening-balances' => ['noun' => 'opening balance', 'hub' => null, 'status_enum' => Inv\OpeningBalanceStatus::class,
                'document_type' => DocumentType::OPENING_BALANCE, 'steps' => ['draft', 'pending_approval', 'approved', 'posted'], 'editable' => ['draft'],
                'actions' => [
                    'submit' => ['from' => ['draft'], 'primary' => true],
                    'approve' => ['from' => ['pending_approval'], 'primary' => true],
                    'post' => ['from' => ['approved'], 'primary' => true, 'label' => 'Post opening balance'],
                    'cancel' => ['from' => ['draft', 'pending_approval', 'approved']],
                ],
                'queues' => [
                    'approve' => $approve('Opening balances to approve', ['pending_approval'], 'inventory.opening_balance.approve'),
                    'post' => $act('Opening balances to post', ['approved'], 'inventory.opening_balance.post'),
                ]],

            // ---- Ledger corrections (Stock → Reversals; Administration → Oversight) ----------
            // Requested from a movement's page; a second person approves (the service enforces it) and the reversal posts.
            'movement-reversals' => ['noun' => 'stock reversal', 'hub' => null, 'status_enum' => Inv\ReversalStatus::class,
                'document_type' => null, 'steps' => ['pending_approval', 'posted'], 'editable' => [],
                'actions' => [],
                'extra' => [
                    'approve' => ['route' => 'api.v1.wf.reversals.approve', 'from' => ['pending_approval'], 'primary' => true, 'label' => 'Approve and post',
                        'params' => ['reversalId' => 'id']],
                    'reject' => ['route' => 'api.v1.wf.reversals.reject', 'from' => ['pending_approval'], 'params' => ['reversalId' => 'id']],
                ],
                'queues' => [
                    'approve' => $approve('Stock reversals to approve', ['pending_approval'], 'inventory.movement.reverse', ['sod' => null]),
                ]],
            'reconciliation-findings' => ['noun' => 'stock difference', 'hub' => null, 'status_enum' => Inv\ReconciliationFindingStatus::class,
                'document_type' => null, 'steps' => ['open', 'repaired'], 'editable' => [],
                'actions' => [],
                'extra' => [
                    'repair' => ['route' => 'api.v1.wf.reconciliation.findings.repair', 'from' => ['open', 'investigating'], 'primary' => true,
                        'label' => 'Repair the stock figures', 'params' => ['findingId' => 'id']],
                ],
                'queues' => [
                    'repair' => $act('Stock differences to repair', ['open', 'investigating'], 'inventory.reconciliation.repair'),
                ]],
        ];
    }

    /** @return array<string, mixed>|null */
    public static function document(string $resource): ?array
    {
        return self::documents()[$resource] ?? null;
    }

    /**
     * Every queue, keyed `resource.queue`, with its resource and hubs resolved.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function queues(): array
    {
        $out = [];
        foreach (self::documents() as $resource => $doc) {
            foreach ($doc['queues'] as $key => $queue) {
                $out["$resource.$key"] = $queue + ['key' => "$resource.$key", 'resource' => $resource, 'document_type' => $doc['document_type'],
                    'hubs' => $doc['hub'] === null ? [] : [$doc['hub']]];
            }
        }

        return $out;
    }
}
