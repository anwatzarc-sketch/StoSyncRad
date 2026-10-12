@php($brand = app(\App\Domains\Platform\Services\BrandResolver::class)->publicProfile())
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset app · {{ $brand['app_name'] }}</title>
    <style nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; font-family: system-ui, sans-serif; }
        main { max-width: 28rem; padding: 2rem 1.25rem; text-align: center; line-height: 1.5; }
    </style>
</head>
<body>
<main>
    <h1>Resetting {{ $brand['app_short_name'] }}</h1>
    <p id="status">Removing the installed app cache…</p>
</main>
<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
    (async () => {
        const status = document.getElementById('status');
        try {
            if ('serviceWorker' in navigator) {
                for (const registration of await navigator.serviceWorker.getRegistrations()) {
                    await registration.unregister();
                }
            }
            if ('caches' in window) {
                for (const key of await caches.keys()) {
                    await caches.delete(key);
                }
            }
            status.textContent = 'Done. Reloading…';
            setTimeout(() => window.location.replace('/'), 800);
        } catch (e) {
            status.textContent = 'Could not reset automatically. Clear this site\'s data in your browser settings, then reload.';
        }
    })();
</script>
</body>
</html>
