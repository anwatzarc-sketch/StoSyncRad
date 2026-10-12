<?php

use App\Domains\Billing\Schemas\PaymentConfirmationSchema;
use App\Domains\CustomerPortal\Schemas as Cps;
use App\Domains\IdentityAccess\Schemas as IdS;
use App\Domains\Sales\Schemas\QuotationRequestSchema;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Portal\PortalCommerceController as Commerce;
use App\Http\Controllers\Portal\PortalController as Portal;
use App\Http\Controllers\Portal\PortalPublicController as PublicPortal;
use App\Http\Controllers\Portal\PortalTeamController as Team;
use Illuminate\Support\Facades\Route;

/*
| Customer Portal API (CP-1.1). Only exists while feature.customer_portal is on ('portal' middleware).
| public/* serves visitors (listed in platform.public_route_names); everything else needs a signed-in
| customer account and is bound to a customer.* permission. The account audience middleware keeps
| staff sessions out of these routes and customer sessions out of the staff API.
*/

Route::prefix('portal')->name('portal.')->middleware('portal')->group(function (): void {
    Route::prefix('public')->name('public.')->middleware('throttle:portal-public')->group(function (): void {
        Route::get('info', [PublicPortal::class, 'info'])->name('info');
        Route::post('register', [PublicPortal::class, 'register'])->middleware('throttle:portal-auth')->name('register')->schema(Cps\PortalRegistrationSchema::class);
        Route::post('register/resend', [PublicPortal::class, 'resendVerification'])->middleware('throttle:portal-auth')->name('register.resend')
            ->schema(Cps\PortalResendVerificationSchema::class);
        Route::post('verify-email', [PublicPortal::class, 'verifyEmail'])->middleware('throttle:portal-auth')->name('verify_email')->schema(Cps\PortalTokenSchema::class);
        Route::get('invitation', [PublicPortal::class, 'invitation'])->middleware('throttle:portal-auth')->name('invitation');
        Route::post('invitation/accept', [PublicPortal::class, 'acceptInvitation'])->middleware('throttle:portal-auth')->name('invitation.accept')
            ->schema(Cps\PortalInvitationAcceptSchema::class);
        Route::post('security/begin', [PublicPortal::class, 'beginSecurity'])->middleware('throttle:portal-auth')->name('security.begin')->noBody();
        Route::post('security/confirm', [PublicPortal::class, 'confirmSecurity'])->middleware('throttle:portal-auth')->name('security.confirm')->schema(IdS\MfaCodeSchema::class);
        Route::post('login', [AuthController::class, 'portalLogin'])->middleware('throttle:portal-auth')->name('login')->schema(IdS\LoginSchema::class);
        Route::post('mfa', [AuthController::class, 'mfa'])->middleware('throttle:portal-auth')->name('mfa')->schema(IdS\MfaVerifySchema::class);
    });

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('me', [Portal::class, 'me'])->name('me')->permission('customer.account.manage');
        Route::get('dashboard', [Portal::class, 'dashboard'])->name('dashboard')->permission('customer.order.read');
        Route::get('notifications', [Portal::class, 'notifications'])->name('notifications')->permission('customer.account.manage');
        Route::post('notifications/{id}/read', [Portal::class, 'readNotification'])->whereUuid('id')->name('notifications.read')
            ->permission('customer.account.manage')->noBody();
        Route::post('account/password', [AuthController::class, 'changePassword'])->middleware('throttle:portal-action')->name('account.password')
            ->permission('customer.account.manage')->schema(IdS\ChangePasswordSchema::class);
        Route::post('account/authenticator/begin', [Portal::class, 'beginAuthenticator'])->middleware('throttle:portal-action')->name('account.authenticator.begin')
            ->permission('customer.account.manage')->schema(IdS\MfaVerifySchema::class);
        Route::post('account/authenticator/confirm', [Portal::class, 'confirmAuthenticator'])->middleware('throttle:portal-action')->name('account.authenticator.confirm')
            ->permission('customer.account.manage')->schema(IdS\MfaCodeSchema::class);

        Route::get('team', [Team::class, 'index'])->name('team')->permission('customer.member.read');
        Route::post('team/invitations', [Team::class, 'invite'])->middleware('throttle:portal-action')->name('team.invite')
            ->permission('customer.member.manage')->schema(Cps\PortalTeamInvitationSchema::class);
        Route::post('team/invitations/{id}/revoke', [Team::class, 'revokeInvitation'])->whereUuid('id')->name('team.invitations.revoke')
            ->permission('customer.member.manage')->noBody();
        Route::post('team/members/{id}/role', [Team::class, 'role'])->whereUuid('id')->name('team.members.role')
            ->permission('customer.member.manage')->schema(Cps\PortalTeamRoleSchema::class);
        Route::post('team/members/{id}/deactivate', [Team::class, 'deactivate'])->whereUuid('id')->name('team.members.deactivate')
            ->permission('customer.member.manage')->noBody();
        Route::post('team/members/{id}/reactivate', [Team::class, 'reactivate'])->whereUuid('id')->name('team.members.reactivate')
            ->permission('customer.member.manage')->noBody();

        Route::get('catalog', [Portal::class, 'catalog'])->name('catalog')->permission('customer.catalog.browse');
        Route::get('catalog/categories', [Portal::class, 'categories'])->name('catalog.categories')->permission('customer.catalog.browse');
        Route::get('catalog/items/{id}', [Portal::class, 'item'])->whereUuid('id')->name('catalog.item')->permission('customer.catalog.browse');
        Route::get('catalog/items/{id}/image', [Portal::class, 'itemImage'])->whereUuid('id')->name('catalog.image')->permission('customer.catalog.browse');

        Route::get('quotations', [Commerce::class, 'quotations'])->name('quotations')->permission('customer.quotation.read');
        Route::post('quotations', [Commerce::class, 'requestQuotation'])->middleware('throttle:portal-action')->name('quotations.request')
            ->permission('customer.quotation.request')->schema(QuotationRequestSchema::class);
        Route::get('quotations/{id}', [Commerce::class, 'quotation'])->whereUuid('id')->name('quotations.show')->permission('customer.quotation.read');
        Route::get('quotations/{id}/document', [Commerce::class, 'quotationDocument'])->whereUuid('id')->name('quotations.document')->permission('customer.quotation.read');
        Route::post('quotations/{id}/accept', [Commerce::class, 'acceptQuotation'])->whereUuid('id')->name('quotations.accept')
            ->permission('customer.quotation.respond')->noBody();
        Route::post('quotations/{id}/decline', [Commerce::class, 'declineQuotation'])->whereUuid('id')->name('quotations.decline')
            ->permission('customer.quotation.respond')->schema(Cps\PortalNoteSchema::class);
        Route::post('quotations/{id}/revise', [Commerce::class, 'reviseQuotation'])->whereUuid('id')->name('quotations.revise')
            ->permission('customer.quotation.respond')->schema(Cps\PortalNoteSchema::class);

        Route::get('orders', [Commerce::class, 'orders'])->name('orders')->permission('customer.order.read');
        Route::post('orders', [Commerce::class, 'placeOrder'])->middleware('throttle:portal-action')->name('orders.place')
            ->permission('customer.order.place')->schema(Cps\PortalOrderSchema::class);
        Route::get('orders/{id}', [Commerce::class, 'order'])->whereUuid('id')->name('orders.show')->permission('customer.order.read');
        Route::post('orders/{id}/cancel', [Commerce::class, 'cancelOrder'])->whereUuid('id')->name('orders.cancel')
            ->permission('customer.order.cancel')->schema(Cps\PortalNoteSchema::class);
        Route::post('orders/{id}/acknowledge', [Commerce::class, 'acknowledgeDelivery'])->whereUuid('id')->name('orders.acknowledge')
            ->permission('customer.delivery.confirm')->schema(Cps\DeliveryAcknowledgementSchema::class);
        Route::post('orders/{id}/shipments/{shipmentId}/acknowledge', [Commerce::class, 'acknowledgeDelivery'])->whereUuid(['id', 'shipmentId'])
            ->name('orders.shipments.acknowledge')->permission('customer.delivery.confirm')->schema(Cps\DeliveryAcknowledgementSchema::class);

        Route::get('invoices', [Commerce::class, 'invoices'])->name('invoices')->permission('customer.invoice.read');
        Route::get('invoices/{id}', [Commerce::class, 'invoice'])->whereUuid('id')->name('invoices.show')->permission('customer.invoice.read');
        Route::get('invoices/{id}/document', [Commerce::class, 'invoiceDocument'])->whereUuid('id')->name('invoices.document')->permission('customer.invoice.read');

        Route::get('payments', [Commerce::class, 'payments'])->name('payments')->permission('customer.payment.read');
        Route::post('payments', [Commerce::class, 'submitPayment'])->middleware('throttle:portal-action')->name('payments.submit')
            ->permission('customer.payment.submit')->schema(PaymentConfirmationSchema::class);
        Route::get('payments/{id}', [Commerce::class, 'payment'])->whereUuid('id')->name('payments.show')->permission('customer.payment.read');
        Route::post('payments/{id}/information', [Commerce::class, 'addPaymentInformation'])->whereUuid('id')->middleware('throttle:portal-action')->name('payments.information')
            ->permission('customer.payment.submit')->schema(Cps\PortalNoteSchema::class);
        Route::post('payments/{id}/withdraw', [Commerce::class, 'withdrawPayment'])->whereUuid('id')->name('payments.withdraw')
            ->permission('customer.payment.submit')->noBody();
        Route::get('payments/{id}/evidence/{attachmentId}', [Commerce::class, 'evidence'])->whereUuid(['id', 'attachmentId'])->name('payments.evidence')
            ->permission('customer.payment.read');
    });
});
