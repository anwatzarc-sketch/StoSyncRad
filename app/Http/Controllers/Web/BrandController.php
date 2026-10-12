<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Platform\Models\BrandAsset;
use App\Domains\Platform\Services\BrandKitService;
use App\Domains\Platform\Services\BrandResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * White-label delivery (Chapter 03 §3.5, Chapter 12 §12.2): manifest, theme tokens and brand assets
 * come from the active brand kit at request time — re-branding never needs a rebuild. Responses are
 * public and validated by the kit checksum (ETag) so caches refresh when the kit changes.
 */
final class BrandController
{
    public function __construct(private readonly BrandResolver $brand, private readonly BrandKitService $kits) {}

    public function manifest(Request $request): SymfonyResponse
    {
        $profile = $this->brand->publicProfile();

        return $this->cached($request, (string) $profile['checksum'], fn () => response()->json($this->kits->manifest($profile), 200,
            ['Content-Type' => 'application/manifest+json']));
    }

    public function theme(Request $request): SymfonyResponse
    {
        $profile = $this->brand->publicProfile();

        return $this->cached($request, (string) $profile['checksum'], fn () => new Response($this->kits->themeCss($profile), 200, ['Content-Type' => 'text/css; charset=UTF-8']));
    }

    public function asset(Request $request, string $path): SymfonyResponse
    {
        $asset = BrandAsset::query()->where('public_path', $path)->first();
        abort_if($asset === null || ! Storage::disk('private')->exists($asset->path), 404);

        return $this->cached($request, (string) ($asset->checksum ?? $asset->id), fn () => new Response(Storage::disk('private')->get($asset->path), 200, [
            'Content-Type' => $asset->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ]), 86400);
    }

    private function cached(Request $request, string $checksum, \Closure $make, int $maxAge = 3600): SymfonyResponse
    {
        $etag = '"'.substr(hash('sha256', $checksum), 0, 32).'"';
        if ($request->headers->get('If-None-Match') === $etag) {
            return new Response('', 304, ['ETag' => $etag]);
        }
        $response = $make();
        $response->headers->set('ETag', $etag);
        $response->headers->set('Cache-Control', "public, max-age={$maxAge}");

        return $response;
    }
}
