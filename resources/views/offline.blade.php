@php($brand = app(\App\Domains\Platform\Services\BrandResolver::class)->publicProfile())
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Offline · {{ $brand['app_name'] }}</title>
    <link rel="stylesheet" href="/brand/theme.css?v={{ urlencode((string) $brand['checksum']) }}">
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; font-family: var(--ssr-font, system-ui, sans-serif);
               background: var(--ssr-surface, #fff); color: var(--ssr-text, #111); }
        main { max-width: 28rem; padding: 2rem 1.25rem; text-align: center; }
        h1 { font-size: 1.375rem; margin: 0 0 .75rem; }
        p { color: var(--ssr-text-muted, #555); line-height: 1.5; }
        a { display: inline-block; margin-top: 1.25rem; padding: .75rem 1.25rem; border-radius: var(--ssr-radius, .5rem);
            background: var(--ssr-brand, #0f2a47); color: var(--ssr-brand-contrast, #fff); text-decoration: none; min-height: 44px; box-sizing: border-box; }
    </style>
</head>
<body>
<main>
    <h1>You are offline</h1>
    <p>{{ $brand['app_name'] }} needs a connection to show stock, post documents or run reports. Nothing was saved while offline,
       and nothing will be sent later — when you are back online, check the document you were working on and continue.</p>
    <a href="/">Try again</a>
</main>
</body>
</html>
