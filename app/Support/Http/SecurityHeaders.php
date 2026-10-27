<?php

declare(strict_types=1);

namespace App\Support\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser hardening for web and API responses (Chapter 12 §12.11, Chapter 14): nonce-based CSP (no
 * inline scripts), no framing, no sniffing, strict referrer, camera only for this origin (scanning),
 * HSTS on HTTPS. Signed-in pages and API answers are never stored by the browser or a proxy (shared
 * warehouse devices, the back button after signing out) unless the response chose its own caching.
 * While the Vite dev server runs, its origin is allowed for scripts and HMR.
 */
final class SecurityHeaders
{
    /** Cache-Control directives that mean the response set its own caching on purpose. */
    private const CHOSEN_CACHING = ['no-store', 'max-age', 's-maxage', 'public', 'immutable'];

    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();
        $response = $next($request);

        $dev = '';
        if (is_file(public_path('hot'))) {
            $origin = rtrim(trim((string) file_get_contents(public_path('hot'))), '/');
            $ws = preg_replace('#^http#', 'ws', $origin);
            $dev = " $origin $ws";
        }
        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'".$dev,
            "style-src 'self' 'unsafe-inline'".$dev,
            "img-src 'self' data: blob:",
            "font-src 'self' data:".$dev,
            "connect-src 'self'".$dev,
            "worker-src 'self'",
            "manifest-src 'self'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ]);
        $headers = [
            'Content-Security-Policy' => $csp,
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'same-origin',
            'Permissions-Policy' => 'camera=(self), microphone=(), geolocation=(), payment=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];
        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }
        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }
        if (($request->is('api/*') || $request->user() !== null) && ! $this->choseCaching($response)) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }

    private function choseCaching(Response $response): bool
    {
        foreach (self::CHOSEN_CACHING as $directive) {
            if ($response->headers->hasCacheControlDirective($directive)) {
                return true;
            }
        }

        return false;
    }
}
