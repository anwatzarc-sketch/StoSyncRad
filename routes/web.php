<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web (Inertia) routes. Feature route files live in routes/web/*.php.
|--------------------------------------------------------------------------
*/

Route::get('/health/live', fn () => response()->json(['status' => 'ok'], 200, ['Cache-Control' => 'no-store']))->name('health.live');

foreach (glob(__DIR__.'/web/*.php') ?: [] as $file) {
    require $file;
}
