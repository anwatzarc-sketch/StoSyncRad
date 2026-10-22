<?php

use App\Http\Controllers\Web\MarketingController;
use Illuminate\Support\Facades\Route;

/*
| Public product website (feature.marketing_site). The home page shares '/' with the dashboard and is
| routed in app.php; every other page answers 404 while the website is off.
*/

Route::name('marketing.')->group(function (): void {
    foreach (array_keys(MarketingController::PAGES) as $path) {
        if ($path !== '/') {
            Route::get($path, [MarketingController::class, 'show'])->name(ltrim($path, '/'));
        }
    }
    Route::get('/sitemap.xml', [MarketingController::class, 'sitemap'])->name('sitemap');
    Route::get('/robots.txt', [MarketingController::class, 'robots'])->name('robots');
});
