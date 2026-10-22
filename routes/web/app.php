<?php

use App\Domains\Platform\Services\PlatformHealth;
use App\Http\Controllers\Web\BrandController;
use App\Http\Controllers\Web\MarketingController;
use App\Http\Controllers\Web\PagesController;
use Illuminate\Support\Facades\Route;

/*
| Application shell (Chapter 12): brand delivery, readiness, PWA support pages and Inertia pages.
*/

// ---- White-label delivery and PWA support (reachable before setup) ------------------------------
Route::get('/manifest.webmanifest', [BrandController::class, 'manifest'])->name('brand.manifest');
Route::get('/brand/theme.css', [BrandController::class, 'theme'])->name('brand.theme');
Route::get('/brand/{path}', [BrandController::class, 'asset'])->where('path', '[A-Za-z0-9_./-]+')->name('brand.asset');
Route::view('/offline', 'offline')->name('offline');
Route::get('/sw.js', function () {
    // The service worker is built into public/build but must be served from the root to control '/'.
    $path = public_path('build/sw.js');
    abort_unless(is_file($path), 404);

    return response()->file($path, ['Content-Type' => 'application/javascript; charset=UTF-8', 'Cache-Control' => 'no-cache', 'Service-Worker-Allowed' => '/']);
})->name('pwa.sw');
Route::view('/pwa/reset', 'pwa-reset')->name('pwa.reset');
Route::get('/health/ready', function (PlatformHealth $health) {
    $ready = $health->ready();

    return response()->json(['status' => in_array(false, $ready, true) ? 'unavailable' : 'ok'] + $ready, in_array(false, $ready, true) ? 503 : 200,
        ['Cache-Control' => 'no-store']);
})->name('health.ready');

// ---- Sign-in -----------------------------------------------------------------------------------------
Route::middleware('guest')->group(function (): void {
    Route::inertia('/login', 'Auth/Login')->name('login');
    Route::inertia('/password/reset', 'Auth/ResetPassword')->name('password.reset.page');
});

// ---- Application pages (data via /api/v1; every rule enforced there) ---------------------------------
// '/' is the dashboard once signed in; visitors get the product website there (MarketingController).
Route::get('/', [MarketingController::class, 'home'])->name('app.dashboard');

Route::middleware('auth')->name('app.')->group(function (): void {
    Route::inertia('/account', 'Account/Security')->name('account');
    Route::inertia('/notifications', 'Notifications')->name('notifications');
    Route::inertia('/scan', 'Stock/Scan')->name('scan');
    Route::inertia('/stock', 'Stock/Search')->name('stock');
    Route::get('/stock/items/{id}', [PagesController::class, 'stockItem'])->whereUuid('id')->name('stock.item');
    Route::inertia('/trace', 'Inventory/Trace')->name('trace');
    Route::inertia('/valuation', 'Inventory/Valuation')->name('valuation');
    Route::inertia('/planning/replenishment', 'Planning/Replenishment')->name('replenishment');
    Route::inertia('/carriers', 'Partners/Carriers')->name('carriers');
    Route::inertia('/imports', 'Exchange/Imports')->name('imports');

    Route::inertia('/reports', 'Reports/Catalog')->name('reports');
    Route::inertia('/reports/runs', 'Reports/Runs')->name('reports.runs');
    Route::get('/reports/new', [PagesController::class, 'report'])->name('reports.new');
    Route::get('/reports/{id}', [PagesController::class, 'report'])->whereUuid('id')->name('reports.show');

    Route::get('/r/{resource}', [PagesController::class, 'resourceIndex'])->where('resource', '[a-z-]+')->name('resources.index');
    Route::get('/r/{resource}/new', [PagesController::class, 'resourceCreate'])->where('resource', '[a-z-]+')->name('resources.create');
    Route::get('/r/{resource}/{id}', [PagesController::class, 'resourceShow'])->where('resource', '[a-z-]+')->whereUuid('id')->name('resources.show');
    Route::get('/r/{resource}/{id}/edit', [PagesController::class, 'resourceEdit'])->where('resource', '[a-z-]+')->whereUuid('id')->name('resources.edit');

    Route::prefix('tasks')->name('tasks.')->group(function (): void {
        Route::get('/transfers/{id}/dispatch', fn (string $id) => Inertia\Inertia::render('Tasks/TransferDispatch', ['transferId' => $id]))->whereUuid('id')->name('dispatch');
        Route::get('/transfers/{id}/receive', fn (string $id) => Inertia\Inertia::render('Tasks/TransferReceive', ['transferId' => $id]))->whereUuid('id')->name('receive');
        Route::get('/stocktakes/{id}/count', fn (string $id) => Inertia\Inertia::render('Tasks/StocktakeCount', ['stocktakeId' => $id]))->whereUuid('id')->name('count');
        Route::get('/pick-lists/{id}/pick', fn (string $id) => Inertia\Inertia::render('Tasks/PickList', ['pickListId' => $id]))->whereUuid('id')->name('pick');
    });

    Route::prefix('admin')->name('admin.')->group(function (): void {
        foreach (['company' => 'Company', 'config' => 'Configuration', 'brand' => 'Brand', 'templates' => 'Templates', 'lookups' => 'Lookups',
            'numbering' => 'Numbering', 'bundles' => 'Bundles', 'webhooks' => 'Webhooks', 'api-tokens' => 'ApiTokens', 'audit' => 'Audit',
            'security-events' => 'SecurityEvents', 'health' => 'Health'] as $path => $page) {
            Route::inertia("/$path", "Admin/$page")->name(str_replace('-', '_', $path));
        }
    });
});
