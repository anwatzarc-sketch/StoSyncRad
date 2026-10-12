<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 (Chapter 11). Every route is bound to exactly one permission via
| ->permission('module.resource.action') unless listed in platform.public_route_names.
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    foreach (glob(__DIR__.'/api/*.php') ?: [] as $file) {
        require $file;
    }
});
