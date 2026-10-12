<?php

use App\Http\Controllers\Api\ResourceController;
use App\Http\Support\ResourceRegistry;
use Illuminate\Support\Facades\Route;

/*
| Generated CRUD for every registered resource (App\Http\Support\ResourceRegistry): list/show bound
| to the read permission, create/update bound to their permissions and validated by the resource's
| property schema through the global validation middleware.
*/

Route::middleware('auth:sanctum')->group(function (): void {
    foreach (ResourceRegistry::all() as $key => $cfg) {
        $name = 'resources.'.str_replace('-', '_', $key);
        Route::get($key, [ResourceController::class, 'index'])->resource($key)->name("$name.index")->permission($cfg['read']);
        Route::get("$key/{id}", [ResourceController::class, 'show'])->resource($key)->name("$name.show")->whereUuid('id')->permission($cfg['read']);
        if (($cfg['writer'] ?? null) === false || $cfg['schema'] === null) {
            continue;
        }
        if (! empty($cfg['create'])) {
            Route::post($key, [ResourceController::class, 'store'])->resource($key)->name("$name.store")->permission($cfg['create'])->schema($cfg['schema'], 'create');
        }
        if (! empty($cfg['update'])) {
            Route::patch("$key/{id}", [ResourceController::class, 'update'])->resource($key)->name("$name.update")->whereUuid('id')
                ->permission($cfg['update'])->schema($cfg['schema'], 'update');
        }
    }
});
