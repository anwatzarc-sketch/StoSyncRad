<?php

use Illuminate\Support\Facades\Route;

/*
| Customer Portal pages (CP-1.1). The whole portal answers 404 while feature.customer_portal is off.
| Public pages (landing, registration, verification, invitation, sign-in, password reset) need no
| account; the rest need a signed-in customer account ('portal:customer'). Data comes from
| /api/v1/portal/* where every rule is enforced again.
*/

Route::prefix('portal')->name('portal.')->middleware('portal')->group(function (): void {
    Route::name('public.')->group(function (): void {
        Route::inertia('/', 'Portal/Public/Landing')->name('landing');
        Route::inertia('/register', 'Portal/Public/Register')->name('register');
        Route::inertia('/verify-email', 'Portal/Public/VerifyEmail')->name('verify_email');
        Route::inertia('/security-setup', 'Portal/Public/SecuritySetup')->name('security_setup');
        Route::inertia('/invitation', 'Portal/Public/Invitation')->name('invitation');
        Route::inertia('/login', 'Portal/Public/Login')->name('login');
        Route::inertia('/password/reset', 'Portal/Public/ResetPassword')->name('password.reset');
    });

    Route::middleware('portal:customer')->group(function (): void {
        Route::inertia('/dashboard', 'Portal/Dashboard')->name('dashboard');
        Route::inertia('/catalog', 'Portal/Catalog')->name('catalog');
        Route::get('/catalog/{id}', fn (string $id) => Inertia\Inertia::render('Portal/Product', ['productId' => $id]))->whereUuid('id')->name('product');
        Route::inertia('/cart', 'Portal/Cart')->name('cart');
        Route::inertia('/quotations', 'Portal/Quotations')->name('quotations');
        Route::get('/quotations/{id}', fn (string $id) => Inertia\Inertia::render('Portal/Quotation', ['quotationId' => $id]))->whereUuid('id')->name('quotation');
        Route::inertia('/orders', 'Portal/Orders')->name('orders');
        Route::get('/orders/{id}', fn (string $id) => Inertia\Inertia::render('Portal/Order', ['orderId' => $id]))->whereUuid('id')->name('order');
        Route::inertia('/invoices', 'Portal/Invoices')->name('invoices');
        Route::get('/invoices/{id}', fn (string $id) => Inertia\Inertia::render('Portal/Invoice', ['invoiceId' => $id]))->whereUuid('id')->name('invoice');
        Route::inertia('/payments', 'Portal/Payments')->name('payments');
        Route::get('/payments/{id}', fn (string $id) => Inertia\Inertia::render('Portal/Payment', ['paymentId' => $id]))->whereUuid('id')->name('payment');
        Route::inertia('/account', 'Portal/Account')->name('account');
        Route::inertia('/team', 'Portal/Team')->name('team');
    });
});
