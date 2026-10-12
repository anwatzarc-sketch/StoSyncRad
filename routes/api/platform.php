<?php

use App\Domains\IdentityAccess\Schemas as IdS;
use App\Domains\Messaging\Schemas as MsgS;
use App\Domains\Platform\Schemas as PlatS;
use App\Domains\ReportingEngine\Schemas as RepS;
use App\Http\Controllers\Api\AccountAdminController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\ExchangeController;
use App\Http\Controllers\Api\IntegrationController;
use App\Http\Controllers\Api\ReportingController;
use App\Http\Controllers\Api\StockQueryController;
use App\Support\Documents\Schemas\DecisionSchema;
use Illuminate\Support\Facades\Route;

/*
| Reporting, imports, notifications, printing, stock queries, administration and integrations.
*/

Route::middleware('auth:sanctum')->group(function (): void {
    // ---- Stock queries ----------------------------------------------------------------------
    Route::get('stock/availability', [StockQueryController::class, 'availability'])->name('stock.availability')->permission('inventory.stock.read');
    Route::get('stock/items/{itemId}', [StockQueryController::class, 'item'])->name('stock.item')->whereUuid('itemId')->permission('inventory.stock.read');
    Route::get('stock/items/{itemId}/card', [StockQueryController::class, 'card'])->name('stock.card')->whereUuid('itemId')->permission('inventory.movement.read');
    Route::get('stock/pick-suggestions', [StockQueryController::class, 'pickSuggestions'])->name('stock.pick_suggestions')->permission('inventory.stock.read');
    Route::get('scan/{code}', [StockQueryController::class, 'scan'])->name('scan')->where('code', '[^/]{1,128}')->permission('catalog.item.read');
    Route::get('trace/lots/{lotId}', [StockQueryController::class, 'lotTrace'])->name('trace.lot')->whereUuid('lotId')->permission('inventory.trace.read');
    Route::get('trace/serials/{serialNumber}', [StockQueryController::class, 'serialHistory'])->name('trace.serial')->permission('inventory.trace.read');
    Route::get('planning/demand', [StockQueryController::class, 'demand'])->name('planning.demand')->permission('planning.demand.read');
    Route::get('planning/replenishment', [StockQueryController::class, 'replenishment'])->name('planning.replenishment')->permission('planning.replenishment.read');

    // ---- Reporting -----------------------------------------------------------------------------
    Route::get('reporting/catalog', [ReportingController::class, 'catalog'])->name('reporting.catalog')->permission('reporting.catalog.read');
    Route::get('report-definitions', [ReportingController::class, 'definitions'])->name('reporting.definitions')->permission('reporting.definition.read');
    Route::post('report-definitions', [ReportingController::class, 'createDefinition'])->name('reporting.definitions.store')->schema(RepS\ReportDefinitionSchema::class, 'create')->permission('reporting.definition.create');
    Route::get('report-definitions/{id}', [ReportingController::class, 'definition'])->name('reporting.definitions.show')->whereUuid('id')->permission('reporting.definition.read');
    Route::patch('report-definitions/{id}', [ReportingController::class, 'updateDefinition'])->name('reporting.definitions.update')->schema(RepS\ReportDefinitionSchema::class, 'update')->whereUuid('id')->permission('reporting.definition.update');
    Route::post('report-definitions/{id}/clone', [ReportingController::class, 'cloneDefinition'])->name('reporting.definitions.clone')->schema(RepS\ReportCloneSchema::class)->whereUuid('id')->permission('reporting.definition.clone');
    Route::post('report-definitions/{id}/share', [ReportingController::class, 'shareDefinition'])->name('reporting.definitions.share')->schema(RepS\ReportShareSchema::class)->whereUuid('id')->permission('reporting.definition.share');
    Route::post('report-definitions/{id}/archive', [ReportingController::class, 'archiveDefinition'])->name('reporting.definitions.archive')->schema(DecisionSchema::class)->whereUuid('id')->permission('reporting.definition.archive');
    Route::post('report-definitions/{id}/run', [ReportingController::class, 'run'])->name('reporting.run')->schema(RepS\ReportRunSchema::class)->whereUuid('id')->permission('reporting.report.run');
    Route::post('reports/preview', [ReportingController::class, 'preview'])->name('reporting.preview')->schema(RepS\ReportPreviewSchema::class)->permission('reporting.report.run');
    Route::get('report-runs', [ReportingController::class, 'runs'])->name('reporting.runs')->permission('reporting.report.run');
    Route::get('report-runs/{id}', [ReportingController::class, 'showRun'])->name('reporting.runs.show')->whereUuid('id')->permission('reporting.report.run');
    Route::post('report-runs/{id}/cancel', [ReportingController::class, 'cancel'])->name('reporting.runs.cancel')->noBody()->whereUuid('id')->permission('reporting.report.cancel_own');
    Route::get('report-runs/{id}/download', [ReportingController::class, 'download'])->name('report_runs.download')->whereUuid('id')
        ->middleware('signed')->permission('reporting.report.run');

    // ---- Imports, files, notifications, printing --------------------------------------
    Route::get('imports/types', [ExchangeController::class, 'importTypes'])->name('imports.types')->permission('exchange.import.read');
    Route::get('imports/{id}', [ExchangeController::class, 'batch'])->name('imports.show')->whereUuid('id')->permission('exchange.import.read');
    Route::post('imports/{id}/confirm', [ExchangeController::class, 'confirm'])->name('imports.confirm')->noBody()->whereUuid('id')->permission('exchange.import.read');
    Route::post('imports/{id}/cancel', [ExchangeController::class, 'cancel'])->name('imports.cancel')->noBody()->whereUuid('id')->permission('exchange.import.read');
    Route::post('imports/{id}/errors', [ExchangeController::class, 'errors'])->name('imports.errors')->noBody()->whereUuid('id')->permission('exchange.import.read');
    Route::get('imports/templates/{type}', [ExchangeController::class, 'template'])->name('imports.template')->permission('exchange.import.read');
    Route::get('files/{id}', [ExchangeController::class, 'file'])->name('files.download')->whereUuid('id')->permission('reporting.report.run');
    Route::get('notifications', [ExchangeController::class, 'notifications'])->name('notifications.index')->permission('messaging.notification.read_own');
    Route::post('notifications/mark', [ExchangeController::class, 'markNotifications'])->name('notifications.mark')->schema(MsgS\NotificationMarkSchema::class)->permission('messaging.notification.read_own');
    Route::get('notifications/preferences', [ExchangeController::class, 'preferences'])->name('notifications.preferences')->permission('messaging.notification.manage_own_preferences');
    Route::put('notifications/preferences', [ExchangeController::class, 'setPreference'])->name('notifications.preferences.update')->schema(MsgS\NotificationPreferenceSchema::class)->permission('messaging.notification.manage_own_preferences');
    Route::get('print/{type}/{id}', [ExchangeController::class, 'print'])->name('print')->whereUuid('id')->permission('platform.meta.read');

    // ---- Administration ---------------------------------------------------------------------
    Route::prefix('admin')->name('admin.')->group(function (): void {
        Route::get('config', [AdminController::class, 'config'])->name('config')->permission('platform.config.read');
        Route::put('config/{key}', [AdminController::class, 'setConfig'])->name('config.update')->schema(PlatS\ConfigValueSchema::class)->where('key', '[a-z0-9_.]+')->permission('platform.config.read');
        Route::post('mail/test', [AdminController::class, 'testMail'])->middleware('throttle:10,1')->name('mail.test')->schema(PlatS\MailTestSchema::class)
            ->permission('platform.config.messaging_manage');
        Route::get('lookups', [AdminController::class, 'lookups'])->name('lookups')->permission('platform.lookup.read');
        Route::put('lookups/{lookupCode}/values', [AdminController::class, 'saveLookupValue'])->name('lookups.values')->schema(PlatS\LookupValueSaveSchema::class)->permission('platform.lookup.manage');
        Route::get('templates', [AdminController::class, 'templates'])->name('templates')->permission('platform.template.read');
        Route::get('templates/{id}', [AdminController::class, 'template'])->name('templates.show')->whereUuid('id')->permission('platform.template.read');
        Route::put('templates/{id}', [AdminController::class, 'saveTemplate'])->name('templates.update')->schema(PlatS\TemplateSaveSchema::class)->whereUuid('id')->permission('platform.template.manage');
        Route::post('templates/preview', [AdminController::class, 'previewTemplate'])->name('templates.preview')->schema(PlatS\TemplatePreviewSchema::class)->permission('platform.template.manage');
        Route::put('enum-labels', [AdminController::class, 'setEnumLabel'])->name('enum_labels')->schema(PlatS\EnumLabelSchema::class)->permission('platform.enum_label.manage');
        Route::get('webhooks', [AdminController::class, 'webhooks'])->name('webhooks')->permission('messaging.webhook.read');
        Route::post('webhooks', [AdminController::class, 'registerWebhook'])->name('webhooks.store')->schema(PlatS\WebhookRegisterSchema::class)->permission('messaging.webhook.manage')->middleware('step_up');
        Route::post('webhooks/{id}/rotate-secret', [AdminController::class, 'rotateWebhookSecret'])->name('webhooks.rotate')->noBody()->whereUuid('id')->permission('messaging.webhook.manage')->middleware('step_up');
        Route::post('webhooks/{id}/active', [AdminController::class, 'setWebhookActive'])->name('webhooks.active')->schema(PlatS\WebhookActiveSchema::class)->whereUuid('id')->permission('messaging.webhook.manage');
        Route::get('webhook-deliveries', [AdminController::class, 'webhookDeliveries'])->name('webhook_deliveries')->permission('messaging.webhook.read');
        Route::post('webhook-deliveries/{id}/retry', [AdminController::class, 'retryWebhookDelivery'])->name('webhook_deliveries.retry')->noBody()->whereUuid('id')->permission('messaging.webhook.manage');
        Route::get('outbox', [AdminController::class, 'outbox'])->name('outbox')->permission('messaging.outbox.read');
        Route::post('outbox/retry', [AdminController::class, 'retryOutbox'])->name('outbox.retry')->schema(PlatS\OutboxRetrySchema::class)->permission('messaging.outbox.retry');

        Route::post('role-assignments', [AccountAdminController::class, 'assign'])->name('assignments.store')->schema(IdS\RoleAssignmentSchema::class)->permission('identity.assignment.create')->middleware('step_up');
        Route::post('role-assignments/{id}/revoke', [AccountAdminController::class, 'revoke'])->name('assignments.revoke')->schema(DecisionSchema::class)->whereUuid('id')->permission('identity.assignment.revoke');
        Route::post('accounts/{id}/reset-mfa', [AccountAdminController::class, 'resetMfa'])->name('accounts.reset_mfa')->schema(DecisionSchema::class)->whereUuid('id')->permission('identity.account.reset_mfa')->middleware('step_up');
        Route::post('accounts/{id}/revoke-sessions', [AccountAdminController::class, 'revokeSessions'])->name('accounts.revoke_sessions')->schema(IdS\RevokeSessionsSchema::class)->whereUuid('id')->permission('identity.account.revoke_sessions');
        Route::post('accounts/{id}/force-password-change', [AccountAdminController::class, 'forcePasswordChange'])->name('accounts.force_password')->schema(DecisionSchema::class)->whereUuid('id')->permission('identity.account.force_password_change');
        Route::post('roles/{id}/clone', [AccountAdminController::class, 'cloneRole'])->name('roles.clone')->schema(IdS\RoleCloneSchema::class)->whereUuid('id')->permission('identity.role.clone');
        Route::post('roles/{id}/archive', [AccountAdminController::class, 'archiveRole'])->name('roles.archive')->schema(DecisionSchema::class)->whereUuid('id')->permission('identity.role.archive');
        Route::put('accounts-per-person-policy', [AccountAdminController::class, 'accountsPolicy'])->name('accounts_policy')->schema(IdS\AccountsPolicySchema::class)->permission('platform.config.identity_manage')->middleware('step_up');
    });
});

// Inbound integrations authenticate by signature (no session); listed in platform.public_route_names.
Route::post('integrations/carriers/{carrier}/events', [IntegrationController::class, 'carrierEvent'])->name('integrations.carrier_event')->schema(MsgS\CarrierEventSchema::class)
    ->where('carrier', '[a-z0-9_-]{1,40}')->middleware('throttle:120,1');
