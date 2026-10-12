<?php

use App\Domains\Catalog\Schemas as CatS;
use App\Domains\Catalog\Schemas\ItemSchema;
use App\Domains\DataExchange\Enums\ImportType;
use App\Domains\DataExchange\Schemas as DxS;
use App\Domains\Fulfillment\Schemas as FulS;
use App\Domains\Fulfillment\Schemas\StockIssueSchema;
use App\Domains\IdentityAccess\Schemas as IdS;
use App\Domains\Inventory\Schemas as InvS;
use App\Domains\Messaging\Schemas as MsgS;
use App\Domains\Organization\Schemas\CompanyProfileSchema;
use App\Domains\Partners\Schemas as PartS;
use App\Domains\Platform\Schemas as PlatS;
use App\Domains\Procurement\Schemas\PurchaseOrderSchema;
use App\Domains\Receiving\Schemas as RecS;
use App\Domains\ReportingEngine\Enums\ReportOutputFormat;
use App\Domains\ReportingEngine\Schemas as RepS;
use App\Domains\Transfers\Schemas as TrS;
use App\Http\Controllers\Api\AccountAdminController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\ExchangeController;
use App\Http\Controllers\Api\ItemImageController;
use App\Http\Controllers\Api\LifecycleController;
use App\Http\Controllers\Api\OperationsController;
use App\Http\Controllers\Api\PlatformAdminController;
use App\Http\Controllers\Api\ReportingController;
use App\Http\Controllers\Api\ResourceController;
use App\Http\Controllers\Api\TaskSheetController;
use App\Support\Documents\Schemas\DecisionSchema;
use Illuminate\Support\Facades\Route;

/*
| Lifecycle, operational and administrative endpoints outside the generic CRUD and document actions.
| One endpoint per permission (Chapter 05): where one controller method serves several permissions,
| each endpoint carries its own `->variant()` and `->permission()`.
*/

Route::middleware('auth:sanctum')->group(function (): void {
    // ---- Catalog lifecycle -----------------------------------------------------------------
    foreach (['activate', 'deactivate', 'archive'] as $v) {
        Route::post("items/{id}/$v", [LifecycleController::class, 'itemStatus'])->name("items.$v")->whereUuid('id')->variant($v)->permission("catalog.item.$v")->schema(DecisionSchema::class);
    }
    Route::get('items/{id}/barcodes', [LifecycleController::class, 'itemBarcodes'])->name('items.barcodes')->whereUuid('id')->permission('catalog.barcode.read');
    foreach (['barcodes' => 'catalog.barcode.manage', 'packagings' => 'catalog.packaging.manage', 'kit_components' => 'catalog.kit.manage'] as $field => $permission) {
        Route::put('items/{id}/'.str_replace('_', '-', $field), [LifecycleController::class, 'itemCollection'])->name("items.$field.update")->whereUuid('id')
            ->variant($field)->permission($permission)->schema(ItemSchema::class, 'update');
    }
    Route::post('items/{id}/convert-tracking', [LifecycleController::class, 'convertTracking'])->name('items.convert_tracking')->whereUuid('id')
        ->permission('catalog.item.convert_tracking')->schema(CatS\TrackingConversionSchema::class)->middleware('step_up');
    Route::get('items/{id}/images', [ItemImageController::class, 'index'])->name('items.images')->whereUuid('id')->permission('catalog.item.read');
    Route::post('items/{id}/images', [ItemImageController::class, 'store'])->name('items.images.store')->whereUuid('id')->middleware('throttle:60,1')
        ->permission('catalog.item.update')->schema(CatS\ItemImageUploadSchema::class)->ownScreen();
    Route::get('items/{id}/images/{imageId}', [ItemImageController::class, 'show'])->name('items.images.show')->whereUuid(['id', 'imageId'])->permission('catalog.item.read');
    Route::post('items/{id}/images/{imageId}/primary', [ItemImageController::class, 'primary'])->name('items.images.primary')->whereUuid(['id', 'imageId'])
        ->permission('catalog.item.update')->noBody();
    Route::delete('items/{id}/images/{imageId}', [ItemImageController::class, 'destroy'])->name('items.images.destroy')->whereUuid(['id', 'imageId'])
        ->permission('catalog.item.update')->noBody();
    Route::get('items/{id}/label', [ExchangeController::class, 'printItemLabel'])->name('items.label')->whereUuid('id')->permission('catalog.label.print');
    Route::post('item-categories/{id}/archive', [LifecycleController::class, 'categoryArchive'])->name('item_categories.archive')->whereUuid('id')->permission('catalog.category.archive')->schema(DecisionSchema::class);
    Route::post('item-categories/{id}/move', [LifecycleController::class, 'categoryMove'])->name('item_categories.move')->whereUuid('id')->permission('catalog.category.move')->schema(CatS\CategoryMoveSchema::class);

    // ---- Organization ------------------------------------------------------------------------
    Route::post('warehouses/{id}/close', [LifecycleController::class, 'closeWarehouse'])->name('warehouses.close')->whereUuid('id')
        ->permission('organization.warehouse.close')->schema(DecisionSchema::class)->middleware('step_up');
    Route::post('branches/{id}/close', [LifecycleController::class, 'closeBranch'])->name('branches.close')->whereUuid('id')
        ->permission('organization.branch.close')->schema(DecisionSchema::class)->middleware('step_up');
    foreach (['block' => 'block', 'unblock' => 'block', 'archive' => 'archive'] as $v => $permission) {
        Route::post("storage-locations/{id}/$v", [LifecycleController::class, 'locationStatus'])->name("storage_locations.$v")->whereUuid('id')
            ->variant($v)->permission("organization.storage_location.$permission")->schema(DecisionSchema::class);
    }

    // ---- Partners ------------------------------------------------------------------------------
    foreach (['hold' => 'hold', 'blacklist' => 'blacklist', 'archive' => 'archive', 'activate' => 'hold'] as $v => $permission) {
        $route = Route::post("partners/{id}/$v", [LifecycleController::class, 'partnerStatus'])->name("partners.$v")->whereUuid('id')
            ->variant($v)->permission("partners.partner.$permission")->schema(DecisionSchema::class);
        if ($v === 'blacklist') {
            $route->middleware('step_up');
        }
    }
    Route::get('carriers', [LifecycleController::class, 'carriers'])->name('carriers.index')->permission('partners.carrier.read');
    Route::patch('carriers/{id}', [LifecycleController::class, 'updateCarrier'])->name('carriers.update')->whereUuid('id')->permission('partners.carrier.manage')->schema(PartS\CarrierProfileSchema::class);

    Route::post('persons/{id}/archive', [LifecycleController::class, 'archivePerson'])->name('persons.archive')->whereUuid('id')->permission('identity.person.archive')->schema(DecisionSchema::class);

    // ---- Lots, serials, reservations, valuation ----------------------------------------------
    Route::post('lots', [OperationsController::class, 'registerLot'])->name('lots.register')->permission('inventory.lot.register')->schema(InvS\LotRegisterSchema::class);
    Route::patch('lots/{id}', [OperationsController::class, 'updateLot'])->name('lots.update')->whereUuid('id')->permission('inventory.lot.update')->schema(InvS\LotUpdateSchema::class);
    Route::post('lots/{id}/lock', [OperationsController::class, 'lockLot'])->name('lots.lock')->whereUuid('id')->variant('lock')->permission('inventory.lot.lock')->schema(DecisionSchema::class);
    Route::post('lots/{id}/unlock', [OperationsController::class, 'lockLot'])->name('lots.unlock')->whereUuid('id')->variant('unlock')->permission('inventory.lot.unlock')->schema(DecisionSchema::class);
    Route::post('lots/{id}/qc-decision', [OperationsController::class, 'qcDecision'])->name('lots.qc_decision')->whereUuid('id')->permission('receiving.qc.release')->schema(InvS\QcDecisionSchema::class);
    Route::post('serials', [OperationsController::class, 'registerSerial'])->name('serials.register')->permission('inventory.serial.register')->schema(InvS\SerialRegisterSchema::class);
    Route::patch('serials/{id}', [OperationsController::class, 'updateSerial'])->name('serials.update')->whereUuid('id')->permission('inventory.serial.update')->schema(InvS\SerialUpdateSchema::class);
    Route::post('reservations', [OperationsController::class, 'reserve'])->name('reservations.create')->permission('inventory.reservation.create')->schema(InvS\ReservationCreateSchema::class);
    Route::post('reservations/{id}/release', [OperationsController::class, 'releaseReservation'])->name('reservations.release')->whereUuid('id')->permission('inventory.reservation.release')->schema(InvS\ReservationReleaseSchema::class);
    Route::post('reservations/{id}/adjust', [OperationsController::class, 'adjustReservation'])->name('reservations.adjust')->whereUuid('id')->permission('inventory.reservation.adjust')->schema(InvS\ReservationAdjustSchema::class);
    Route::get('valuation', [OperationsController::class, 'valuation'])->name('valuation')->permission('inventory.valuation.read');
    Route::put('valuation/policy/{key}', [AdminController::class, 'setConfig'])->name('valuation.policy')->where('key', 'inventory\.costing_(method|level)')
        ->permission('inventory.valuation.manage_policy')->schema(PlatS\ConfigValueSchema::class)->middleware('step_up');

    // ---- Receiving, transfers, production -----------------------------------------------------
    Route::post('putaway-tasks/{id}/assign', [OperationsController::class, 'assignPutaway'])->name('putaway_tasks.assign')->whereUuid('id')->permission('receiving.putaway.assign')->schema(RecS\PutawayAssignSchema::class);
    Route::post('transfers/{transferId}/discrepancies/{discrepancyId}/investigate', [OperationsController::class, 'investigateDiscrepancy'])->name('transfers.discrepancies.investigate')
        ->whereUuid(['transferId', 'discrepancyId'])->permission('transfers.discrepancy.investigate')->schema(TrS\DiscrepancyInvestigateSchema::class);
    Route::post('production-issues', [OperationsController::class, 'productionIssue'])->name('production_issues.store')
        ->permission('fulfillment.production.issue')->schema(StockIssueSchema::class, 'create');
    Route::patch('purchase-orders/{id}/revise', [ResourceController::class, 'update'])->name('purchase_orders.revise')->whereUuid('id')->resource('purchase-orders')
        ->permission('procurement.purchase_order.revise')->schema(PurchaseOrderSchema::class, 'update');

    // ---- Warehouse-floor task sheets (read-only, denormalized for scan-first screens) ----------------
    Route::get('transfers/{id}/dispatch-sheet', [TaskSheetController::class, 'dispatchSheet'])->name('tasks.dispatch_sheet')->whereUuid('id')
        ->permission('transfers.transfer.dispatch');
    Route::get('transfers/{id}/receipt-sheet', [TaskSheetController::class, 'receiptSheet'])->name('tasks.receipt_sheet')->whereUuid('id')
        ->permission('transfers.transfer.receive');
    Route::get('stocktakes/{id}/count-sheet', [TaskSheetController::class, 'countSheet'])->name('tasks.count_sheet')->whereUuid('id')
        ->permission('stocktaking.stocktake.count');
    Route::get('pick-lists/{id}/sheet', [TaskSheetController::class, 'pickSheet'])->name('tasks.pick_sheet')->whereUuid('id')
        ->permission('fulfillment.pick_list.read');

    // ---- Reporting: one export endpoint per format; all-runs listing --------------------------------
    foreach ([ReportOutputFormat::PDF, ReportOutputFormat::XLSX, ReportOutputFormat::CSV] as $format) {
        Route::post("report-definitions/{id}/exports/{$format->value}", [ReportingController::class, 'export'])->name("reporting.export.{$format->value}")
            ->where('id', '[0-9a-f-]{36}|draft')->variant($format->value)->permission("reporting.report.export_{$format->value}")->schema(RepS\ReportExportSchema::class);
    }
    Route::get('report-runs/all', [ReportingController::class, 'runs'])->name('reporting.runs.all')->variant('all')->permission('reporting.run.read_all');

    // ---- Imports: one upload endpoint per import type ----------------------------------------------
    foreach (ImportType::cases() as $type) {
        Route::post("imports/{$type->value}", [ExchangeController::class, 'upload'])->name("imports.upload.{$type->value}")
            ->variant($type->value)->permission("exchange.import.{$type->value}")->schema(DxS\ImportUploadSchema::class);
    }

    // ---- Audit ------------------------------------------------------------------------------------
    Route::get('audit/events', [PlatformAdminController::class, 'auditEvents'])->name('audit.events')->permission('audit.event.read');
    Route::get('audit/events/export', [PlatformAdminController::class, 'exportAuditEvents'])->name('audit.events.export')->permission('audit.event.export');
    Route::get('audit/approvals', [PlatformAdminController::class, 'approvals'])->name('audit.approvals')->permission('audit.approval.read');

    // ---- Administration ---------------------------------------------------------------------------
    Route::prefix('admin')->name('admin.')->group(function (): void {
        foreach (['activate' => 'active', 'deactivate' => 'deactivated', 'lock' => 'locked', 'suspend' => 'suspended'] as $v => $status) {
            Route::post("accounts/{id}/$v", [AccountAdminController::class, 'setStatus'])->name("accounts.$v")->whereUuid('id')
                ->variant($status)->permission("identity.account.$v")->schema(DecisionSchema::class);
        }
        Route::get('access-review', [PlatformAdminController::class, 'accessReview'])->name('access_review')->permission('identity.access_review.export');
        Route::patch('role-assignments/{id}', [LifecycleController::class, 'updateAssignment'])->name('assignments.update')->whereUuid('id')->permission('identity.assignment.update')->schema(IdS\RoleAssignmentUpdateSchema::class);
        Route::patch('sod-rules/{id}', [LifecycleController::class, 'updateSodRule'])->name('sod_rules.update')->whereUuid('id')
            ->permission('identity.sod_rule.manage')->schema(IdS\SodRuleUpdateSchema::class)->middleware('step_up');
        Route::patch('approval-policies/{id}', [LifecycleController::class, 'updateApprovalPolicy'])->name('approval_policies.update')->whereUuid('id')
            ->permission('identity.approval_policy.manage')->schema(IdS\ApprovalPolicyUpdateSchema::class)->middleware('step_up');
        Route::get('security-events', [LifecycleController::class, 'securityEvents'])->name('security_events')->permission('identity.security_event.read');
        Route::get('api-tokens', [LifecycleController::class, 'tokens'])->name('api_tokens')->permission('identity.api_token.read');
        Route::post('api-tokens', [LifecycleController::class, 'issueToken'])->name('api_tokens.issue')->permission('identity.api_token.issue')->schema(IdS\ApiTokenIssueSchema::class)->middleware('step_up');
        Route::delete('api-tokens/{id}', [LifecycleController::class, 'revokeToken'])->name('api_tokens.revoke')->whereUuid('id')->permission('identity.api_token.revoke');

        Route::get('company', [LifecycleController::class, 'company'])->name('company')->permission('platform.company.read');
        Route::put('company', [LifecycleController::class, 'updateCompany'])->name('company.update')->permission('platform.company.manage')
            ->schema(CompanyProfileSchema::class, 'update');
        Route::get('numbering', [LifecycleController::class, 'sequences'])->name('numbering')->permission('platform.numbering.manage');
        Route::put('numbering/{id}', [LifecycleController::class, 'updateSequence'])->name('numbering.update')->whereUuid('id')->permission('platform.numbering.manage')->schema(PlatS\NumberingUpdateSchema::class);
        Route::get('translations', [LifecycleController::class, 'translations'])->name('translations')->permission('platform.translation.manage');
        Route::put('translations', [LifecycleController::class, 'setTranslation'])->name('translations.update')->permission('platform.translation.manage')->schema(PlatS\TranslationSchema::class);
        Route::get('inventory-locations', [LifecycleController::class, 'inventoryLocations'])->name('inventory_locations')->permission('platform.inventory_locations.manage');
        Route::post('setup/reopen', [LifecycleController::class, 'reopenSetup'])->name('setup.reopen')->permission('platform.setup.reopen')->schema(DecisionSchema::class)->middleware('step_up');

        Route::get('brand', [PlatformAdminController::class, 'brand'])->name('brand')->permission('platform.brand.read');
        Route::post('brand/assets', [PlatformAdminController::class, 'uploadBrandAsset'])->name('brand.assets')->permission('platform.brand.manage')->schema(PlatS\BrandAssetUploadSchema::class);
        Route::post('brand', [PlatformAdminController::class, 'activateBrand'])->name('brand.activate')->permission('platform.brand.manage')->schema(PlatS\BrandKitSchema::class);
        Route::get('config-bundle', [PlatformAdminController::class, 'exportBundle'])->name('config_bundle.export')->permission('platform.bundle.export');
        Route::post('config-bundle', [PlatformAdminController::class, 'importBundle'])->name('config_bundle.import')->permission('platform.bundle.import')->schema(PlatS\ConfigBundleSchema::class)->middleware('step_up');
        Route::get('health', [PlatformAdminController::class, 'health'])->name('health')->permission('platform.health.read');
        Route::post('maintenance', [PlatformAdminController::class, 'maintenance'])->name('maintenance')->permission('platform.platform.manage')->schema(PlatS\MaintenanceSchema::class)->middleware('step_up');
    });

    // ---- Service-account intake (Bearer token of an account holding the permission) ---------------
    Route::post('intake/sales-orders', [PlatformAdminController::class, 'intakeOrder'])->name('intake.sales_orders')
        ->permission('fulfillment.sales_order.intake_api')->schema(FulS\SalesOrderSchema::class, 'create')->middleware('throttle:600,1');
    Route::post('intake/carrier-events', [PlatformAdminController::class, 'carrierEventByToken'])->name('intake.carrier_events')
        ->permission('messaging.carrier_webhook.receive')->schema(MsgS\CarrierEventSchema::class)->middleware('throttle:600,1');
});
