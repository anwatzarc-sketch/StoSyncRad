<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Domains\Billing\Models as Bill;
use App\Domains\Billing\Schemas as BillS;
use App\Domains\Billing\Services as BillW;
use App\Domains\Catalog\Actions\SaveCategoryAction;
use App\Domains\Catalog\Actions\SaveItemAction;
use App\Domains\Catalog\Models as Cat;
use App\Domains\Catalog\Schemas as CatS;
use App\Domains\CustomerPortal\Models as Cp;
use App\Domains\CustomerPortal\Schemas\CustomerInvitationSchema;
use App\Domains\CustomerPortal\Services\InvitationService;
use App\Domains\Fulfillment\Models as Ful;
use App\Domains\Fulfillment\Schemas as FulS;
use App\Domains\Fulfillment\Services as FulW;
use App\Domains\IdentityAccess\Actions\CreateUserAccountAction;
use App\Domains\IdentityAccess\Actions\SaveRoleAction;
use App\Domains\IdentityAccess\Models as Id;
use App\Domains\IdentityAccess\Schemas as IdS;
use App\Domains\Inventory\Documents as InvW;
use App\Domains\Inventory\Models as Inv;
use App\Domains\Inventory\Schemas as InvS;
use App\Domains\Organization\Actions\CreateBranchAction;
use App\Domains\Organization\Actions\CreateStorageLocationAction;
use App\Domains\Organization\Actions\CreateWarehouseAction;
use App\Domains\Organization\Models as Org;
use App\Domains\Organization\Schemas as OrgS;
use App\Domains\Partners\Actions\SavePartnerAction;
use App\Domains\Partners\Models\Partner;
use App\Domains\Partners\Schemas\PartnerSchema;
use App\Domains\Planning\Models as Plan;
use App\Domains\Planning\Schemas as PlanS;
use App\Domains\Planning\Services\AllocationPlanWorkflow;
use App\Domains\Procurement\Models as Pro;
use App\Domains\Procurement\Schemas as ProS;
use App\Domains\Procurement\Schemas\SupplierReturnSchema;
use App\Domains\Procurement\Services as ProW;
use App\Domains\Procurement\Services\SupplierReturnWorkflow;
use App\Domains\Receiving\Models as Rec;
use App\Domains\Receiving\Schemas as RecS;
use App\Domains\Receiving\Services\GoodsReceiptWorkflow;
use App\Domains\Sales\Models\Quotation;
use App\Domains\Stocktaking\Models as Stk;
use App\Domains\Stocktaking\Schemas as StkS;
use App\Domains\Stocktaking\Services as StkW;
use App\Domains\Transfers\Models as Tr;
use App\Domains\Transfers\Schemas as TrS;
use App\Domains\Transfers\Services\TransferWorkflow;

/**
 * Every listable/editable resource of the API in one place (Chapter 11 §11.2): model, property
 * schema, read/create/update permissions, warehouse scope columns, detail relations and the writer
 * (a domain action or workflow `save()`; null = schema-driven ResourceWriter; false = read-only).
 * Routes, permission bindings and the global schema validation are generated from this table.
 */
final class ResourceRegistry
{
    /**
     * @return array<string, array{model: class-string, schema: ?class-string, read: string, create?: ?string, update?: ?string,
     *     warehouse?: array<int, string>, own?: array{0: string, 1: string}, with?: array<int, string>, writer?: mixed, feature?: ?string, server?: array}>
     */
    public static function all(): array
    {
        $doc = fn (string $model, ?string $schema, string $perm, $writer, array $warehouse = ['warehouse_id'], array $with = ['lines'], ?string $feature = null, ?array $own = null) => [
            'model' => $model, 'schema' => $schema, 'read' => "$perm.read", 'create' => "$perm.create", 'update' => "$perm.update",
            'warehouse' => $warehouse, 'with' => $with, 'writer' => $writer, 'feature' => $feature, 'own' => $own,
        ];

        return [
            // ---- Catalog ------------------------------------------------------------------
            'items' => ['model' => Cat\Item::class, 'schema' => CatS\ItemSchema::class, 'read' => 'catalog.item.read', 'create' => 'catalog.item.create',
                'update' => 'catalog.item.update', 'writer' => [SaveItemAction::class, 'execute'], 'with' => ['barcodes', 'packagings', 'variants', 'category']],
            'item-categories' => ['model' => Cat\ItemCategory::class, 'schema' => CatS\ItemCategorySchema::class, 'read' => 'catalog.category.read',
                'create' => 'catalog.category.create', 'update' => 'catalog.category.update', 'writer' => [SaveCategoryAction::class, 'execute']],
            'item-variants' => ['model' => Cat\ItemVariant::class, 'schema' => null, 'read' => 'catalog.variant.read', 'writer' => false],
            'units' => ['model' => Cat\UnitOfMeasure::class, 'schema' => CatS\UnitOfMeasureSchema::class, 'read' => 'catalog.uom.read',
                'create' => 'catalog.uom.manage', 'update' => 'catalog.uom.manage', 'server' => ['status' => 'active']],
            'uom-categories' => ['model' => Cat\UomCategory::class, 'schema' => CatS\UomCategorySchema::class, 'read' => 'catalog.uom.read',
                'create' => 'catalog.uom.manage', 'update' => 'catalog.uom.manage'],
            'tags' => ['model' => Cat\Tag::class, 'schema' => CatS\TagSchema::class, 'read' => 'catalog.item.read', 'create' => 'catalog.tag.manage', 'update' => 'catalog.tag.manage'],
            'item-attributes' => ['model' => Cat\ItemAttribute::class, 'schema' => CatS\ItemAttributeSchema::class, 'read' => 'catalog.variant.read',
                'create' => 'catalog.variant.manage', 'update' => 'catalog.variant.manage', 'with' => ['values']],
            'reorder-rules' => ['model' => Cat\ItemReorderRule::class, 'schema' => CatS\ItemReorderRuleSchema::class, 'read' => 'catalog.reorder_rule.read',
                'create' => 'catalog.reorder_rule.manage', 'update' => 'catalog.reorder_rule.manage', 'warehouse' => ['warehouse_id']],
            'vendor-pricelists' => ['model' => Cat\ItemVendorPricelist::class, 'schema' => CatS\ItemVendorPricelistSchema::class, 'read' => 'catalog.vendor_pricelist.read',
                'create' => 'catalog.vendor_pricelist.manage', 'update' => 'catalog.vendor_pricelist.manage'],
            'qc-templates' => ['model' => Cat\QcChecklistTemplate::class, 'schema' => CatS\QcChecklistTemplateSchema::class, 'read' => 'catalog.qc_template.read',
                'create' => 'catalog.qc_template.manage', 'update' => 'catalog.qc_template.manage'],

            // ---- Organization & partners -----------------------------------------------
            'branches' => ['model' => Org\Branch::class, 'schema' => OrgS\BranchSchema::class, 'read' => 'organization.branch.read',
                'create' => 'organization.branch.create', 'update' => 'organization.branch.update', 'writer' => [CreateBranchAction::class, 'execute']],
            'warehouses' => ['model' => Org\Warehouse::class, 'schema' => OrgS\WarehouseSchema::class, 'read' => 'organization.warehouse.read',
                'create' => 'organization.warehouse.create', 'update' => 'organization.warehouse.update', 'warehouse' => ['id'],
                'writer' => [CreateWarehouseAction::class, 'execute']],
            'storage-locations' => ['model' => Org\StorageLocation::class, 'schema' => OrgS\StorageLocationSchema::class, 'read' => 'organization.storage_location.read',
                'create' => 'organization.storage_location.create', 'update' => 'organization.storage_location.update', 'warehouse' => ['warehouse_id'],
                'writer' => [CreateStorageLocationAction::class, 'execute']],
            'storage-categories' => ['model' => Org\StorageCategory::class, 'schema' => OrgS\StorageCategorySchema::class, 'read' => 'organization.storage_location.read',
                'create' => 'organization.storage_location.create', 'update' => 'organization.storage_location.update'],
            'putaway-rules' => ['model' => Org\PutawayRule::class, 'schema' => OrgS\PutawayRuleSchema::class, 'read' => 'organization.putaway_rule.read',
                'create' => 'organization.putaway_rule.manage', 'update' => 'organization.putaway_rule.manage', 'warehouse' => ['warehouse_id']],
            'partners' => ['model' => Partner::class, 'schema' => PartnerSchema::class, 'read' => 'partners.partner.read', 'create' => 'partners.partner.create',
                'update' => 'partners.partner.update', 'writer' => [SavePartnerAction::class, 'execute']],

            // ---- Identity ----------------------------------------------------------------
            'persons' => ['model' => Id\Person::class, 'schema' => IdS\PersonSchema::class, 'read' => 'identity.person.read', 'create' => 'identity.person.create',
                'update' => 'identity.person.update'],
            'roles' => ['model' => Id\Role::class, 'schema' => IdS\RoleSchema::class, 'read' => 'identity.role.read', 'create' => 'identity.role.create',
                'update' => 'identity.role.update', 'writer' => [SaveRoleAction::class, 'execute'], 'with' => ['permissions']],
            'accounts' => ['model' => Id\UserAccount::class, 'schema' => IdS\UserAccountSchema::class, 'read' => 'identity.account.read',
                'create' => 'identity.account.create', 'update' => 'identity.account.update', 'writer' => [CreateUserAccountAction::class, 'execute'],
                'with' => ['person', 'assignments']],
            'role-assignments' => ['model' => Id\RoleAssignment::class, 'schema' => null, 'read' => 'identity.assignment.read', 'writer' => false],
            'sod-rules' => ['model' => Id\SodRule::class, 'schema' => null, 'read' => 'identity.sod_rule.read', 'writer' => false],
            'approval-policies' => ['model' => Id\ApprovalPolicy::class, 'schema' => null, 'read' => 'identity.approval_policy.read', 'writer' => false],

            // ---- Procurement & receiving ---------------------------------------------
            'purchase-requests' => $doc(Pro\PurchaseRequest::class, ProS\PurchaseRequestSchema::class, 'procurement.purchase_request', [ProW\PurchaseRequestWorkflow::class, 'save']),
            'purchase-orders' => $doc(Pro\PurchaseOrder::class, ProS\PurchaseOrderSchema::class, 'procurement.purchase_order', [ProW\PurchaseOrderWorkflow::class, 'save']),
            'supplier-returns' => $doc(Pro\SupplierReturn::class, SupplierReturnSchema::class, 'procurement.supplier_return',
                [SupplierReturnWorkflow::class, 'save']),
            'goods-receipts' => $doc(Rec\GoodsReceipt::class, RecS\GoodsReceiptSchema::class, 'receiving.goods_receipt', [GoodsReceiptWorkflow::class, 'save']),
            'putaway-tasks' => ['model' => Rec\PutawayTask::class, 'schema' => null, 'read' => 'receiving.putaway.read', 'warehouse' => ['warehouse_id'], 'writer' => false],

            // ---- Transfers -----------------------------------------------------------------
            'transfers' => $doc(Tr\TransferOrder::class, TrS\TransferOrderSchema::class, 'transfers.transfer', [TransferWorkflow::class, 'save'],
                ['source_warehouse_id', 'destination_warehouse_id'], ['lines', 'dispatches', 'receipts', 'discrepancies']),
            'transfer-discrepancies' => ['model' => Tr\TransferDiscrepancy::class, 'schema' => null, 'read' => 'transfers.discrepancy.read', 'writer' => false],

            // ---- Fulfillment ---------------------------------------------------------------
            'stock-requests' => $doc(Ful\StockRequest::class, FulS\StockRequestSchema::class, 'fulfillment.stock_request', [FulW\StockRequestWorkflow::class, 'save'],
                own: ['fulfillment.stock_request.read_own', 'requester_id']),
            'stock-issues' => $doc(Ful\StockIssue::class, FulS\StockIssueSchema::class, 'fulfillment.stock_issue', [FulW\StockIssueWorkflow::class, 'save']),
            'internal-returns' => ['model' => Ful\InternalReturn::class, 'schema' => null, 'read' => 'fulfillment.internal_return.read', 'warehouse' => ['warehouse_id'],
                'with' => ['lines'], 'writer' => false],
            'pick-lists' => ['model' => Ful\PickList::class, 'schema' => null, 'read' => 'fulfillment.pick_list.read', 'warehouse' => ['warehouse_id'], 'with' => ['lines'], 'writer' => false],
            'sales-orders' => $doc(Ful\SalesOrder::class, FulS\SalesOrderSchema::class, 'fulfillment.sales_order', [FulW\SalesOrderWorkflow::class, 'save']),
            'shipments' => ['model' => Ful\Shipment::class, 'schema' => null, 'read' => 'fulfillment.shipment.read', 'warehouse' => ['warehouse_id'],
                'with' => ['packages', 'events'], 'writer' => false],
            'customer-returns' => ['model' => Ful\CustomerReturn::class, 'schema' => null, 'read' => 'fulfillment.customer_return.read', 'warehouse' => ['warehouse_id'],
                'with' => ['lines'], 'writer' => false, 'feature' => 'feature.customer_returns'],

            // ---- Customer portal & billing (CP-1.1) --------------------------------------
            'customer-registrations' => ['model' => Cp\CustomerRegistration::class, 'schema' => null, 'read' => 'portal.registration.read', 'writer' => false,
                'feature' => 'feature.customer_portal'],
            'customer-invitations' => ['model' => Cp\CustomerInvitation::class, 'schema' => CustomerInvitationSchema::class, 'read' => 'portal.invitation.read',
                'create' => 'portal.invitation.create', 'writer' => [InvitationService::class, 'create'], 'feature' => 'feature.customer_portal'],
            'delivery-acknowledgements' => ['model' => Cp\DeliveryAcknowledgement::class, 'schema' => null, 'read' => 'portal.acknowledgement.read',
                'with' => ['lines'], 'writer' => false, 'feature' => 'feature.customer_portal'],
            'quotations' => ['model' => Quotation::class, 'schema' => null, 'read' => 'sales.quotation.read', 'with' => ['lines'], 'writer' => false,
                'feature' => 'feature.customer_portal'],
            'invoices' => ['model' => Bill\Invoice::class, 'schema' => BillS\InvoiceSchema::class, 'read' => 'billing.invoice.read', 'create' => 'billing.invoice.create',
                'update' => 'billing.invoice.create', 'with' => ['lines', 'allocations'], 'writer' => [BillW\InvoiceWorkflow::class, 'save']],
            'tax-rates' => ['model' => Bill\TaxRate::class, 'schema' => BillS\TaxRateSchema::class, 'read' => 'billing.tax.read', 'create' => 'billing.tax.manage',
                'update' => 'billing.tax.manage', 'writer' => [BillW\TaxRates::class, 'save']],
            'payment-confirmations' => ['model' => Bill\PaymentConfirmation::class, 'schema' => null, 'read' => 'billing.payment_confirmation.read',
                'with' => ['evidence'], 'writer' => false],
            'payments' => ['model' => Bill\Payment::class, 'schema' => BillS\PaymentSchema::class, 'read' => 'billing.payment.read', 'create' => 'billing.payment.record',
                'with' => ['allocations'], 'writer' => [BillW\PaymentService::class, 'record']],

            // ---- Stocktaking -------------------------------------------------------------
            'stocktakes' => $doc(Stk\Stocktake::class, StkS\StocktakeSchema::class, 'stocktaking.stocktake', [StkW\StocktakeWorkflow::class, 'save'], with: []),
            'stock-adjustments' => $doc(Stk\StockAdjustment::class, StkS\StockAdjustmentSchema::class, 'stocktaking.adjustment', [StkW\StockAdjustmentWorkflow::class, 'save']),

            // ---- Inventory documents & ledger ------------------------------------------
            'opening-balances' => ['model' => Inv\OpeningBalanceDocument::class, 'schema' => InvS\OpeningBalanceSchema::class, 'read' => 'inventory.stock.read',
                'create' => 'inventory.opening_balance.create', 'update' => 'inventory.opening_balance.create', 'warehouse' => ['warehouse_id'], 'with' => ['lines'],
                'writer' => [InvW\OpeningBalanceWorkflow::class, 'save']],
            'relocations' => ['model' => Inv\Relocation::class, 'schema' => InvS\RelocationSchema::class, 'read' => 'inventory.stock.read', 'create' => 'inventory.relocation.create',
                'update' => 'inventory.relocation.create', 'warehouse' => ['warehouse_id'], 'with' => ['lines'], 'writer' => [InvW\RelocationWorkflow::class, 'save']],
            'condition-changes' => ['model' => Inv\ConditionChange::class, 'schema' => InvS\ConditionChangeSchema::class, 'read' => 'inventory.stock.read',
                'create' => 'inventory.condition.change', 'update' => 'inventory.condition.change', 'warehouse' => ['warehouse_id'], 'with' => ['lines'],
                'writer' => [InvW\ConditionChangeWorkflow::class, 'save']],
            'scrap-documents' => ['model' => Inv\ScrapDocument::class, 'schema' => InvS\ScrapSchema::class, 'read' => 'inventory.stock.read', 'create' => 'inventory.scrap.create',
                'update' => 'inventory.scrap.create', 'warehouse' => ['warehouse_id'], 'with' => ['lines'], 'writer' => [InvW\ScrapWorkflow::class, 'save']],
            'movements' => ['model' => Inv\StockMovement::class, 'schema' => null, 'read' => 'inventory.movement.read', 'warehouse' => ['warehouse_id', 'counterparty_warehouse_id'],
                'with' => ['lines'], 'writer' => false],
            'lots' => ['model' => Inv\InventoryLot::class, 'schema' => null, 'read' => 'inventory.lot.read', 'writer' => false],
            'asns' => ['model' => Pro\AdvanceShipmentNotice::class, 'schema' => null, 'read' => 'procurement.asn.read', 'writer' => false, 'feature' => 'feature.asn',
                'warehouse' => ['purchase_order_id@purchase_orders.warehouse_id'], 'with' => []],
            'serials' => ['model' => Inv\ItemSerial::class, 'schema' => null, 'read' => 'inventory.serial.read', 'warehouse' => ['current_warehouse_id'], 'writer' => false],
            'reservations' => ['model' => Inv\StockReservation::class, 'schema' => null, 'read' => 'inventory.reservation.read', 'warehouse' => ['warehouse_id'], 'writer' => false],
            'recalls' => ['model' => Inv\Recall::class, 'schema' => null, 'read' => 'inventory.recall.read', 'with' => ['lots'], 'writer' => false, 'feature' => 'feature.recalls'],
            'reconciliation-runs' => ['model' => Inv\ReconciliationRun::class, 'schema' => null, 'read' => 'inventory.reconciliation.read', 'writer' => false],
            'reconciliation-findings' => ['model' => Inv\ReconciliationFinding::class, 'schema' => null, 'read' => 'inventory.reconciliation.read', 'writer' => false],

            // ---- Planning ----------------------------------------------------------------
            'allocation-plans' => ['model' => Plan\AllocationPlan::class, 'schema' => PlanS\AllocationPlanSchema::class, 'read' => 'planning.allocation_plan.read',
                'create' => 'planning.allocation_plan.create', 'update' => 'planning.allocation_plan.update', 'with' => ['lines'],
                'writer' => [AllocationPlanWorkflow::class, 'save'], 'feature' => 'feature.allocation_planning'],
            'forecast-inputs' => ['model' => Plan\ForecastInput::class, 'schema' => PlanS\ForecastInputSchema::class, 'read' => 'planning.demand.read',
                'create' => 'planning.forecast_input.manage', 'update' => 'planning.forecast_input.manage', 'warehouse' => []],
        ];
    }

    /** @return array<string, mixed> */
    public static function get(string $key): array
    {
        return self::all()[$key] ?? throw new \InvalidArgumentException("Unknown resource `$key`.");
    }
}
