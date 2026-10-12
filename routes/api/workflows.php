<?php

use App\Domains\Fulfillment\Schemas as FulS;
use App\Domains\Fulfillment\Services as Ful;
use App\Domains\Inventory\Documents as InvDoc;
use App\Domains\Inventory\Schemas as InvS;
use App\Domains\Inventory\Services as Inv;
use App\Domains\Planning\Schemas as PlanS;
use App\Domains\Planning\Services as Plan;
use App\Domains\Procurement\Schemas as ProS;
use App\Domains\Procurement\Services as Pro;
use App\Domains\Receiving\Schemas as RecS;
use App\Domains\Receiving\Services as Rec;
use App\Domains\Stocktaking\Schemas as StkS;
use App\Domains\Stocktaking\Services as Stk;
use App\Domains\Transfers\Schemas as TrS;
use App\Domains\Transfers\Services\TransferWorkflow;
use App\Http\Controllers\Api\ResourceController;
use App\Http\Controllers\Api\WorkflowActionController;
use App\Support\Documents\Schemas\DecisionSchema;
use Illuminate\Support\Facades\Route;

/*
| Document actions (Chapter 08). Each route = one service method, one permission, one payload schema.
| Posting actions require an `Idempotency-Key` header (Chapter 09); warehouse scope, state machine,
| SoD and approval limits are enforced inside the services.
*/

$action = function (string $verb, string $uri, string $class, string $method, string $permission, ?string $schema = DecisionSchema::class): \Illuminate\Routing\Route {
    $name = 'wf.'.trim(preg_replace('/[^a-z0-9]+/', '.', strtolower(preg_replace('/\{[^}]+\}/', '', $uri))), '.');
    $route = Route::match([$verb], $uri, WorkflowActionController::class)->workflow($class, $method)->permission($permission)->name($name);

    return $schema !== null && $verb !== 'GET' ? $route->schema($schema, 'create') : $route;
};
$post = fn (string $uri, string $class, string $method, string $permission, ?string $schema = DecisionSchema::class) => $action('POST', $uri, $class, $method, $permission, $schema);
$get = fn (string $uri, string $class, string $method, string $permission) => $action('GET', $uri, $class, $method, $permission, null);

Route::middleware('auth:sanctum')->group(function () use ($post, $get): void {
    // ---- Procurement -----------------------------------------------------------------------
    $post('purchase-requests/{id}/submit', Pro\PurchaseRequestWorkflow::class, 'submit', 'procurement.purchase_request.submit');
    $post('purchase-requests/{id}/approve', Pro\PurchaseRequestWorkflow::class, 'approve', 'procurement.purchase_request.approve');
    $post('purchase-requests/{id}/reject', Pro\PurchaseRequestWorkflow::class, 'reject', 'procurement.purchase_request.reject');
    $post('purchase-requests/{id}/return', Pro\PurchaseRequestWorkflow::class, 'returnForCorrection', 'procurement.purchase_request.reject');
    $post('purchase-requests/{id}/cancel', Pro\PurchaseRequestWorkflow::class, 'cancel', 'procurement.purchase_request.cancel');
    $post('purchase-orders/{id}/submit', Pro\PurchaseOrderWorkflow::class, 'submit', 'procurement.purchase_order.submit');
    $post('purchase-orders/{id}/approve', Pro\PurchaseOrderWorkflow::class, 'approve', 'procurement.purchase_order.approve');
    $post('purchase-orders/{id}/reject', Pro\PurchaseOrderWorkflow::class, 'reject', 'procurement.purchase_order.reject', ProS\PurchaseOrderRejectSchema::class);
    $post('purchase-orders/{id}/send', Pro\PurchaseOrderWorkflow::class, 'send', 'procurement.purchase_order.send');
    $post('purchase-orders/{id}/close', Pro\PurchaseOrderWorkflow::class, 'close', 'procurement.purchase_order.close');
    $post('purchase-orders/{id}/cancel', Pro\PurchaseOrderWorkflow::class, 'cancel', 'procurement.purchase_order.cancel');
    $post('supplier-returns/{id}/submit', Pro\SupplierReturnWorkflow::class, 'submit', 'procurement.supplier_return.submit');
    $post('supplier-returns/{id}/approve', Pro\SupplierReturnWorkflow::class, 'approve', 'procurement.supplier_return.approve');
    $post('supplier-returns/{id}/post', Pro\SupplierReturnWorkflow::class, 'post', 'procurement.supplier_return.post');
    $post('supplier-returns/{id}/cancel', Pro\SupplierReturnWorkflow::class, 'cancel', 'procurement.supplier_return.cancel');

    // ---- Receiving ---------------------------------------------------------------------------
    $post('goods-receipts/{id}/submit', Rec\GoodsReceiptWorkflow::class, 'submit', 'receiving.goods_receipt.submit');
    $post('goods-receipts/{id}/approve', Rec\GoodsReceiptWorkflow::class, 'approve', 'receiving.goods_receipt.approve');
    $post('goods-receipts/{id}/lines/{lineId}/quality-check', Rec\GoodsReceiptWorkflow::class, 'recordQualityCheck', 'receiving.qc.inspect', RecS\QualityCheckSchema::class);
    $post('goods-receipts/{id}/post', Rec\GoodsReceiptWorkflow::class, 'post', 'receiving.goods_receipt.post', RecS\ReceiptPostingSchema::class);
    $post('goods-receipts/{id}/cancel', Rec\GoodsReceiptWorkflow::class, 'cancel', 'receiving.goods_receipt.cancel');
    $post('putaway-tasks/{taskId}/post', Rec\PutawayService::class, 'post', 'receiving.putaway.post', RecS\PutawayPostSchema::class);

    // ---- Transfers -----------------------------------------------------------------------------
    $post('transfers/{id}/submit', TransferWorkflow::class, 'submit', 'transfers.transfer.submit');
    $post('transfers/{id}/approve', TransferWorkflow::class, 'approve', 'transfers.transfer.approve', TrS\TransferApprovalSchema::class);
    $post('transfers/{id}/reject', TransferWorkflow::class, 'reject', 'transfers.transfer.approve');
    $post('transfers/{id}/cancel', TransferWorkflow::class, 'cancel', 'transfers.transfer.cancel');
    $post('transfers/{id}/dispatch', TransferWorkflow::class, 'dispatch', 'transfers.transfer.dispatch', TrS\TransferDispatchSchema::class);
    $post('transfers/{id}/receive', TransferWorkflow::class, 'receive', 'transfers.transfer.receive', TrS\TransferReceiptSchema::class);
    $post('transfers/{transferId}/discrepancies/{discrepancyId}/resolve', TransferWorkflow::class, 'resolveDiscrepancy', 'transfers.discrepancy.resolve',
        TrS\DiscrepancyResolutionSchema::class);
    $post('transfers/{id}/close', TransferWorkflow::class, 'close', 'transfers.transfer.close');

    // ---- Fulfillment ------------------------------------------------------------------------
    Route::post('stock-requests/mine', [ResourceController::class, 'store'])->resource('stock-requests')->name('wf.stock_requests.mine')
        ->permission('fulfillment.stock_request.create_own')->schema(FulS\StockRequestSchema::class, 'create');
    $post('stock-requests/{id}/submit', Ful\StockRequestWorkflow::class, 'submit', 'fulfillment.stock_request.submit');
    $post('stock-requests/{id}/approve', Ful\StockRequestWorkflow::class, 'approve', 'fulfillment.stock_request.approve', FulS\LineApprovalSchema::class);
    $post('stock-requests/{id}/reject', Ful\StockRequestWorkflow::class, 'reject', 'fulfillment.stock_request.reject');
    $post('stock-requests/{id}/cancel', Ful\StockRequestWorkflow::class, 'cancel', 'fulfillment.stock_request.cancel');
    $post('stock-issues/{id}/submit', Ful\StockIssueWorkflow::class, 'submit', 'fulfillment.stock_issue.submit');
    $post('stock-issues/{id}/approve', Ful\StockIssueWorkflow::class, 'approve', 'fulfillment.stock_issue.approve');
    $post('stock-issues/{id}/post', Ful\StockIssueWorkflow::class, 'post', 'fulfillment.stock_issue.post');
    $post('stock-issues/{id}/cancel', Ful\StockIssueWorkflow::class, 'cancel', 'fulfillment.stock_issue.cancel');
    $post('stock-issues/{stockIssueId}/returns', Ful\InternalReturnWorkflow::class, 'create', 'fulfillment.internal_return.create', FulS\ReturnSchema::class);
    $post('internal-returns/{id}/inspect', Ful\InternalReturnWorkflow::class, 'inspect', 'fulfillment.internal_return.inspect', FulS\InspectionSchema::class);
    $post('internal-returns/{id}/approve', Ful\InternalReturnWorkflow::class, 'approve', 'fulfillment.internal_return.approve');
    $post('internal-returns/{id}/post', Ful\InternalReturnWorkflow::class, 'post', 'fulfillment.internal_return.post');
    $post('internal-returns/{id}/cancel', Ful\InternalReturnWorkflow::class, 'cancel', 'fulfillment.internal_return.create');
    $post('sales-orders/{id}/confirm', Ful\SalesOrderWorkflow::class, 'confirm', 'fulfillment.sales_order.confirm');
    $post('sales-orders/{id}/deliveries', Ful\SalesOrderWorkflow::class, 'deliver', 'fulfillment.sales_order.deliver', FulS\PickedLinesSchema::class);
    $post('sales-orders/{id}/cancel', Ful\SalesOrderWorkflow::class, 'cancel', 'fulfillment.sales_order.cancel');
    $post('sales-orders/{salesOrderId}/shipments', Ful\ShipmentWorkflow::class, 'pack', 'fulfillment.shipment.pack', FulS\PickedLinesSchema::class);
    $post('shipments/{id}/handover', Ful\ShipmentWorkflow::class, 'handover', 'fulfillment.shipment.handover', FulS\HandoverSchema::class);
    $post('shipments/{id}/events', Ful\ShipmentWorkflow::class, 'recordEvent', 'fulfillment.shipment.update_status', FulS\ShipmentEventSchema::class);
    $post('shipments/{id}/cancel', Ful\ShipmentWorkflow::class, 'cancel', 'fulfillment.shipment.pack');
    $post('sales-orders/{salesOrderId}/returns', Ful\CustomerReturnWorkflow::class, 'request', 'fulfillment.customer_return.authorize', FulS\ReturnSchema::class);
    $post('customer-returns/{id}/authorize', Ful\CustomerReturnWorkflow::class, 'authorize', 'fulfillment.customer_return.authorize');
    $post('customer-returns/{id}/reject', Ful\CustomerReturnWorkflow::class, 'reject', 'fulfillment.customer_return.authorize');
    $post('customer-returns/{id}/receive', Ful\CustomerReturnWorkflow::class, 'receive', 'fulfillment.customer_return.receive');
    $post('customer-returns/{id}/inspect', Ful\CustomerReturnWorkflow::class, 'inspect', 'fulfillment.customer_return.inspect', FulS\InspectionSchema::class);
    $post('customer-returns/{id}/post', Ful\CustomerReturnWorkflow::class, 'post', 'fulfillment.customer_return.post');
    $post('customer-returns/{id}/cancel', Ful\CustomerReturnWorkflow::class, 'cancel', 'fulfillment.customer_return.authorize');
    $post('pick-lists', Ful\PickListService::class, 'generate', 'fulfillment.pick_list.assign', FulS\PickListGenerateSchema::class);
    $post('pick-waves', Ful\PickListService::class, 'wave', 'fulfillment.pick_wave.manage', FulS\PickWaveSchema::class);
    $post('pick-lists/{id}/picks', Ful\PickListService::class, 'recordPicks', 'fulfillment.pick_list.pick', FulS\PickRecordSchema::class);
    $post('pick-lists/{id}/complete', Ful\PickListService::class, 'complete', 'fulfillment.pick_list.complete');
    $get('pick-lists/{id}/picked', Ful\PickListService::class, 'pickedPayload', 'fulfillment.pick_list.read');

    // ---- Stocktaking & adjustments ----------------------------------------------------------
    $post('stocktakes/{id}/schedule', Stk\StocktakeWorkflow::class, 'schedule', 'stocktaking.stocktake.schedule');
    $post('stocktakes/{id}/start', Stk\StocktakeWorkflow::class, 'start', 'stocktaking.stocktake.schedule');
    $post('stocktakes/{id}/counts', Stk\StocktakeWorkflow::class, 'count', 'stocktaking.stocktake.count', StkS\StocktakeCountSchema::class);
    $post('stocktakes/{id}/finish-counting', Stk\StocktakeWorkflow::class, 'finishCounting', 'stocktaking.stocktake.count');
    $post('stocktakes/{id}/review', Stk\StocktakeWorkflow::class, 'review', 'stocktaking.stocktake.review');
    $post('stocktakes/{id}/recount', Stk\StocktakeWorkflow::class, 'requestRecount', 'stocktaking.stocktake.review', StkS\RecountRequestSchema::class);
    $post('stocktakes/{id}/approve', Stk\StocktakeWorkflow::class, 'approve', 'stocktaking.stocktake.approve');
    $post('stocktakes/{id}/post', Stk\StocktakeWorkflow::class, 'post', 'stocktaking.stocktake.post');
    $post('stocktakes/{id}/cancel', Stk\StocktakeWorkflow::class, 'cancel', 'stocktaking.stocktake.cancel');
    $post('stock-adjustments/{id}/submit', Stk\StockAdjustmentWorkflow::class, 'submit', 'stocktaking.adjustment.submit');
    $post('stock-adjustments/{id}/approve', Stk\StockAdjustmentWorkflow::class, 'approve', 'stocktaking.adjustment.approve');
    $post('stock-adjustments/{id}/reject', Stk\StockAdjustmentWorkflow::class, 'reject', 'stocktaking.adjustment.approve');
    $post('stock-adjustments/{id}/post', Stk\StockAdjustmentWorkflow::class, 'post', 'stocktaking.adjustment.post');
    $post('stock-adjustments/{id}/cancel', Stk\StockAdjustmentWorkflow::class, 'cancel', 'stocktaking.adjustment.cancel');

    // ---- Inventory documents, reversals, recalls, reconciliation -------------------------
    $post('opening-balances/{id}/submit', InvDoc\OpeningBalanceWorkflow::class, 'submit', 'inventory.opening_balance.create');
    $post('opening-balances/{id}/approve', InvDoc\OpeningBalanceWorkflow::class, 'approve', 'inventory.opening_balance.approve');
    $post('opening-balances/{id}/post', InvDoc\OpeningBalanceWorkflow::class, 'post', 'inventory.opening_balance.post');
    $post('opening-balances/{id}/cancel', InvDoc\OpeningBalanceWorkflow::class, 'cancel', 'inventory.opening_balance.create');
    $post('relocations/{id}/post', InvDoc\RelocationWorkflow::class, 'post', 'inventory.relocation.post');
    $post('relocations/{id}/cancel', InvDoc\RelocationWorkflow::class, 'cancel', 'inventory.relocation.create');
    $post('condition-changes/{id}/post', InvDoc\ConditionChangeWorkflow::class, 'post', 'inventory.condition.change');
    $post('condition-changes/{id}/cancel', InvDoc\ConditionChangeWorkflow::class, 'cancel', 'inventory.condition.change');
    $post('scrap-documents/{id}/submit', InvDoc\ScrapWorkflow::class, 'submit', 'inventory.scrap.create');
    $post('scrap-documents/{id}/approve', InvDoc\ScrapWorkflow::class, 'approve', 'inventory.scrap.approve');
    $post('scrap-documents/{id}/post', InvDoc\ScrapWorkflow::class, 'post', 'inventory.scrap.post');
    $post('scrap-documents/{id}/cancel', InvDoc\ScrapWorkflow::class, 'cancel', 'inventory.scrap.create');
    $post('movements/{movementId}/reversals', Inv\ReversalService::class, 'request', 'inventory.movement.reverse', InvS\ReversalRequestSchema::class)->middleware('step_up');
    $post('reversals/{reversalId}/approve', Inv\ReversalService::class, 'approve', 'inventory.movement.reverse')->middleware('step_up');
    $post('reversals/{reversalId}/reject', Inv\ReversalService::class, 'reject', 'inventory.movement.reverse');
    $post('recalls', Inv\RecallService::class, 'initiate', 'inventory.recall.initiate', InvS\RecallSchema::class)->middleware('step_up');
    $post('recalls/{recallId}/close', Inv\RecallService::class, 'close', 'inventory.recall.close');
    $get('recalls/{recallId}/affected', Inv\RecallService::class, 'affected', 'inventory.recall.read');
    $post('reconciliation/runs', Inv\ReconciliationService::class, 'run', 'inventory.reconciliation.run', InvS\ReconciliationRunSchema::class);
    $post('reconciliation/findings/{findingId}/repair', Inv\ReconciliationService::class, 'repair', 'inventory.reconciliation.repair')->middleware('step_up');

    // ---- Planning ----------------------------------------------------------------------------
    $post('reorder-rules/{reorderRuleId}/convert', Plan\ReplenishmentService::class, 'convert', 'planning.replenishment.convert', PlanS\ReplenishmentConvertSchema::class);
    $post('allocation-plans/{id}/suggest', Plan\AllocationPlanWorkflow::class, 'suggest', 'planning.allocation_plan.update');
    $post('allocation-plans/{id}/submit', Plan\AllocationPlanWorkflow::class, 'submit', 'planning.allocation_plan.submit');
    $post('allocation-plans/{id}/approve', Plan\AllocationPlanWorkflow::class, 'approve', 'planning.allocation_plan.approve');
    $post('allocation-plans/{id}/release', Plan\AllocationPlanWorkflow::class, 'release', 'planning.allocation_plan.release');
    $post('allocation-plans/{id}/cancel', Plan\AllocationPlanWorkflow::class, 'cancel', 'planning.allocation_plan.cancel');

    // ---- Landed costs, ASNs, production, transfer picking, recounts, quarantine release ------------
    $post('goods-receipts/{goodsReceiptId}/landed-costs', Rec\LandedCostService::class, 'allocate', 'inventory.landed_cost.manage', RecS\LandedCostSchema::class);
    $post('purchase-orders/{purchaseOrderId}/asns', Pro\AsnService::class, 'create', 'procurement.asn.create', ProS\AsnSchema::class);
    $post('asns/{asnId}/receipt', Pro\AsnService::class, 'prefillReceipt', 'receiving.goods_receipt.create');
    $post('production-receipts', Rec\ProductionReceiptService::class, 'post', 'fulfillment.production.receive', RecS\ProductionReceiptSchema::class);
    $post('transfers/{id}/pick-list', Ful\PickListService::class, 'generateForTransfer', 'transfers.transfer.pick');
    $post('stocktakes/{id}/recounts', Stk\StocktakeWorkflow::class, 'count', 'stocktaking.stocktake.recount', StkS\StocktakeCountSchema::class);
    $post('condition-changes/{id}/release', InvDoc\ConditionChangeWorkflow::class, 'post', 'inventory.quarantine.release');
});
