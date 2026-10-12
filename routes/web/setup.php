<?php

use App\Domains\Platform\Http\Middleware\EnsureSetupSession;
use App\Http\Controllers\Web\SetupController;
use Illuminate\Support\Facades\Route;

/*
| Platform Setup wizard (Chapter 02). Reachable only while setup is open (EnsurePlatformSetupComplete
| answers 404 once it is locked). Everything but the landing page needs an authorized setup session.
*/

Route::prefix('setup')->name('setup.')->middleware('throttle:setup')->group(function (): void {
    Route::get('/', [SetupController::class, 'index'])->name('index');
    Route::post('session', [SetupController::class, 'session'])->name('session')->middleware('throttle:setup-auth');

    Route::middleware(EnsureSetupSession::class)->group(function (): void {
        Route::get('state', [SetupController::class, 'show'])->name('state');
        Route::post('sign-in', [SetupController::class, 'signIn'])->name('sign_in')->middleware('throttle:setup-auth');
        Route::get('steps/{step}', [SetupController::class, 'step'])->name('steps.show');
        Route::post('steps/{step}', [SetupController::class, 'complete'])->name('steps.complete');
        Route::post('steps/{step}/skip', [SetupController::class, 'skip'])->name('steps.skip');
        Route::post('mfa/secret', [SetupController::class, 'mfaSecret'])->name('mfa.secret');
        Route::post('brand/assets', [SetupController::class, 'brandAsset'])->name('brand.assets');
        Route::get('bundle', [SetupController::class, 'bundle'])->name('bundle');
        Route::post('lock', [SetupController::class, 'lock'])->name('lock');
        Route::get('meta/enums', [SetupController::class, 'metaEnums'])->name('meta.enums');
        Route::get('meta/schemas/{name}', [SetupController::class, 'metaSchema'])->name('meta.schema')->where('name', '[a-z0-9_]+');
        Route::get('meta/permissions', [SetupController::class, 'metaPermissions'])->name('meta.permissions');
    });
});
