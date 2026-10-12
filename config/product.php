<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Product identity
|--------------------------------------------------------------------------
| The product is the same for every customer: its name, wordmark, tagline, vendor, logo and icons
| come from here (the only place they are written). The customer's identity — company name,
| colours, letterhead, support contacts — comes from the company profile and the brand kit.
| Icons in public/product are generated from resources/product by `php artisan product:assets`.
*/

return [
    'name' => 'StockSync',
    'short_name' => 'StockSync',
    // Two-tone wordmark: [primary part, accent part].
    'wordmark' => ['Stock', 'Sync'],
    'tagline' => 'Intelligent Stock Management',
    'vendor' => 'Radiant Solutions',
    'vendor_url' => null,

    'colors' => [
        'navy' => '#04326d',      // logo navy — brand, navigation
        'teal' => '#0aaaaa',      // logo teal — decorative (wordmark, mark)
        'teal_ui' => '#087a7b',   // teal darkened for text/buttons on white (WCAG AA)
    ],

    // Bump when the files in public/product change (cache busting).
    'assets_version' => '1',
    'assets' => [
        'logo' => '/product/stocksync-logo.png',            // full lockup, transparent
        'logo_mark' => '/product/stocksync-mark.png',       // "S" mark, transparent
        'favicon_ico' => '/favicon.ico',
        'favicon_16' => '/product/favicon-16.png',
        'favicon_32' => '/product/favicon-32.png',
        'favicon_48' => '/product/favicon-48.png',
        'apple_touch' => '/product/apple-touch-icon.png',
        'icon_192' => '/product/icon-192.png',
        'icon_512' => '/product/icon-512.png',
        'icon_maskable_512' => '/product/icon-maskable-512.png',
    ],
];
