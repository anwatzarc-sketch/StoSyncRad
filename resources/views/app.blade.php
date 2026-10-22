@php($brand = app(\App\Domains\Platform\Services\BrandResolver::class)->publicProfile())
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme-mode="{{ app(\App\Domains\Platform\Config\PlatformConfig::class)->feature('feature.dark_mode') ? 'auto' : 'light' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csp-nonce" content="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
    <meta name="theme-color" content="{{ $brand['theme']['colors']['brand'] ?? '#0f2a47' }}">
    <meta name="application-name" content="{{ $brand['app_short_name'] }}">
    <meta name="apple-mobile-web-app-title" content="{{ $brand['app_short_name'] }}">
    <meta name="apple-mobile-web-app-capable" content="yes">
    @isset($seo)
    {{-- Product website pages: search and link-preview metadata (MarketingController). --}}
    <meta name="description" content="{{ $seo['description'] }}">
    <link rel="canonical" href="{{ $seo['url'] }}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $brand['product']['name'] }}">
    <meta property="og:title" content="{{ $seo['title'] }}">
    <meta property="og:description" content="{{ $seo['description'] }}">
    <meta property="og:url" content="{{ $seo['url'] }}">
    <meta property="og:image" content="{{ $seo['image'] }}">
    <meta name="twitter:card" content="summary">
    <title inertia>{{ $seo['title'] }}</title>
    @else
    <meta name="description" content="{{ $brand['product']['name'] }} — {{ $brand['product']['tagline'] }}">
    <title inertia>{{ $brand['company_name'] ? $brand['app_name'].' · '.$brand['company_name'] : $brand['app_name'] }}</title>
    @endisset
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="stylesheet" href="/brand/theme.css?v={{ urlencode((string) $brand['checksum']) }}">
    <link rel="icon" href="{{ $brand['product']['assets']['favicon_ico'] }}" sizes="48x48">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ $brand['product']['assets']['favicon_32'] }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ $brand['product']['assets']['favicon_16'] }}">
    <link rel="apple-touch-icon" href="{{ $brand['product']['assets']['apple_touch'] }}">
    @vite(['resources/css/app.css', 'resources/js/app.ts'])
    @inertiaHead
</head>
<body class="min-h-dvh bg-surface text-ink antialiased">
    @inertia
    <noscript>{{ $brand['app_name'] }} needs JavaScript to run.</noscript>
</body>
</html>
