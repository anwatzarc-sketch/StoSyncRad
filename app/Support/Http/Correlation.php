<?php

declare(strict_types=1);

namespace App\Support\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Assigns/echoes X-Correlation-Id on every request and shares it with the log context,
 * audit events and outbox messages (Chapter 11 §11.3, Chapter 14 §14.10).
 */
final class Correlation
{
    public const HEADER = 'X-Correlation-Id';

    private static ?string $current = null;

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->headers->get(self::HEADER, '');
        $id = Str::isUuid($incoming) ? $incoming : (string) Str::uuid7();

        self::$current = $id;
        $request->attributes->set('correlation_id', $id);
        Log::withContext(['correlation_id' => $id]);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }

    public static function id(?Request $request = null): string
    {
        if ($request?->attributes->has('correlation_id')) {
            return (string) $request->attributes->get('correlation_id');
        }

        return self::$current ??= (string) Str::uuid7();
    }

    /** Used by console commands and queue jobs to establish their own correlation id. */
    public static function begin(?string $id = null): string
    {
        self::$current = $id ?? (string) Str::uuid7();
        Log::withContext(['correlation_id' => self::$current]);

        return self::$current;
    }
}
