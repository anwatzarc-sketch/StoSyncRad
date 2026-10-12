<?php

use App\Domains\Billing\Schemas as BillS;
use App\Domains\Billing\Services as Bill;
use App\Domains\CustomerPortal\Schemas\RegistrationApprovalSchema;
use App\Domains\CustomerPortal\Services\DeliveryAcknowledgementService;
use App\Domains\CustomerPortal\Services\InvitationService;
use App\Domains\CustomerPortal\Services\RegistrationService;
use App\Domains\Sales\Schemas\QuotationPriceSchema;
use App\Domains\Sales\Services\QuotationWorkflow;
use App\Http\Controllers\Api\BillingFilesController;
use App\Http\Controllers\Api\WorkflowActionController;
use App\Support\Documents\Schemas\DecisionSchema;
use Illuminate\Support\Facades\Route;

/*
| Staff side of the Customer Portal and billing (CP-1.1): registration decisions, invitations, pricing
| quotations, invoices, review of customer payment evidence, payments and delivery discrepancies.
| One route = one service method = one permission; the services enforce state and ownership.
*/

$action = function (string $uri, string $class, string $method, string $permission, ?string $schema = DecisionSchema::class): Illuminate\Routing\Route {
    $name = 'wf.'.trim(preg_replace('/[^a-z0-9]+/', '.', strtolower(preg_replace('/\{[^}]+\}/', '', $uri))), '.');
    $route = Route::post($uri, WorkflowActionController::class)->workflow($class, $method)->permission($permission)->name($name);

    return $schema !== null ? $route->schema($schema, 'create') : $route->noBody();
};

Route::middleware('auth:sanctum')->group(function () use ($action): void {
    $action('customer-registrations/{id}/approve', RegistrationService::class, 'approve', 'portal.registration.approve', RegistrationApprovalSchema::class);
    $action('customer-registrations/{id}/reject', RegistrationService::class, 'reject', 'portal.registration.reject');
    $action('customer-invitations/{id}/revoke', InvitationService::class, 'revoke', 'portal.invitation.revoke');
    $action('delivery-acknowledgements/{id}/resolve', DeliveryAcknowledgementService::class, 'resolve', 'portal.acknowledgement.resolve');

    $action('quotations/{id}/price', QuotationWorkflow::class, 'price', 'sales.quotation.price', QuotationPriceSchema::class);
    $action('quotations/{id}/cancel', QuotationWorkflow::class, 'cancel', 'sales.quotation.cancel');

    $action('partners/{id}/credit-limit', Bill\CreditService::class, 'setLimit', 'billing.credit.manage', BillS\CreditLimitSchema::class);
    $action('sales-orders/{id}/invoice', Bill\InvoiceWorkflow::class, 'fromSalesOrder', 'billing.invoice.create', BillS\InvoiceFromOrderSchema::class);
    $action('shipments/{id}/invoice', Bill\InvoiceWorkflow::class, 'fromShipment', 'billing.invoice.create', null);
    $action('invoices/{id}/issue', Bill\InvoiceWorkflow::class, 'issue', 'billing.invoice.issue');
    $action('invoices/{id}/void', Bill\InvoiceWorkflow::class, 'void', 'billing.invoice.void');

    $action('payment-confirmations/{id}/verify', Bill\PaymentConfirmationWorkflow::class, 'verify', 'billing.payment.record', BillS\PaymentVerificationSchema::class);
    $action('payment-confirmations/{id}/request-information', Bill\PaymentConfirmationWorkflow::class, 'requestInformation', 'billing.payment_confirmation.review');
    $action('payment-confirmations/{id}/reject', Bill\PaymentConfirmationWorkflow::class, 'reject', 'billing.payment_confirmation.review');
    Route::get('payment-confirmations/{id}/evidence/{attachmentId}', [BillingFilesController::class, 'evidence'])->whereUuid(['id', 'attachmentId'])
        ->name('payment_confirmations.evidence')->permission('billing.payment_confirmation.read');

    $action('payments/{id}/allocate', Bill\PaymentService::class, 'allocate', 'billing.payment.allocate', BillS\PaymentAllocationSchema::class);
    $action('payments/{id}/void', Bill\PaymentService::class, 'void', 'billing.payment.void');
});
