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
    <title inertia>{{ $brand['app_name'] }}</title>
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="stylesheet" href="/brand/theme.css?v={{ urlencode((string) $brand['checksum']) }}">
    @isset($brand['assets']['favicon_source'])
        <link rel="icon" href="{{ $brand['assets']['favicon_source'] }}">
    @endisset
    @isset($brand['assets']['icon_180'])
        <link rel="apple-touch-icon" href="{{ $brand['assets']['icon_180'] }}">
    @endisset
    @vite(['resources/css/app.css', 'resources/js/app.ts'])
    @inertiaHead
</head>
<body class="min-h-dvh bg-surface text-ink antialiased">
    @inertia
    <noscript>{{ $brand['app_name'] }} needs JavaScript to run.</noscript>
</body>
</html>
