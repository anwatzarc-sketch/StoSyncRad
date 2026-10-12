<?php

use App\Domains\IdentityAccess\Schemas as IdS;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MetaController;
use Illuminate\Support\Facades\Route;

/*
| Authentication and session metadata. Login/MFA/reset are public; everything else needs a session
| or token. These routes carry no permission: they are listed in platform.public_route_names.
*/

Route::prefix('auth')->name('auth.')->middleware('throttle:20,1')->group(function (): void {
    Route::post('login', [AuthController::class, 'login'])->name('login')->schema(IdS\LoginSchema::class);
    Route::post('mfa', [AuthController::class, 'mfa'])->name('mfa')->schema(IdS\MfaVerifySchema::class);
    Route::post('password/forgot', [AuthController::class, 'forgotPassword'])->name('password.forgot')->schema(IdS\ForgotPasswordSchema::class);
    Route::post('password/reset', [AuthController::class, 'resetPassword'])->name('password.reset')->schema(IdS\ResetPasswordSchema::class);
});

Route::get('meta/brand', [MetaController::class, 'brand'])->name('meta.brand');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout')->noBody();
    Route::post('auth/step-up', [AuthController::class, 'stepUp'])->middleware('throttle:10,1')->name('auth.step_up')->schema(IdS\StepUpSchema::class);
    Route::post('me/password', [AuthController::class, 'changePassword'])->middleware('throttle:10,1')->name('me.password')->schema(IdS\ChangePasswordSchema::class);
    Route::post('me/mfa/enroll', [AuthController::class, 'beginMfa'])->name('me.mfa.enroll')->noBody();
    Route::post('me/mfa/confirm', [AuthController::class, 'confirmMfa'])->middleware('throttle:10,1')->name('me.mfa.confirm')->schema(IdS\MfaCodeSchema::class);
    Route::get('me', [MetaController::class, 'me'])->name('me');
    Route::get('meta/enums', [MetaController::class, 'enums'])->name('meta.enums');
    Route::get('meta/schemas', [MetaController::class, 'schemas'])->name('meta.schemas');
    Route::get('meta/schemas/{name}', [MetaController::class, 'schema'])->name('meta.schema');
    Route::get('meta/permissions', [MetaController::class, 'permissions'])->name('meta.permissions');
    Route::get('meta/config', [MetaController::class, 'clientConfig'])->name('meta.config');
});
