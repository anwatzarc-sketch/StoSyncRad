<?php

declare(strict_types=1);

namespace App\Domains\Platform\Services;

use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Messaging\Services\Outbox;
use App\Domains\Platform\Enums\BrandAssetKind;
use App\Domains\Platform\Models\BrandAsset;
use App\Domains\Platform\Models\BrandKit;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * White-label brand kits (Chapter 03 §3.5).
 *
 * Activation regenerates every derived artifact — PWA icon set, favicon, public logo copies and
 * theme.css — under content-hashed names on the `brand` disk, then invalidates the resolver
 * cache. Nothing is rebuilt: the frontend reads brand data from shared props and /theme.css.
 */
final class BrandKitService
{
    public const ICON_SIZES = [192, 256, 384, 512];

    public const COLOR_PAIRS = [['text', 'surface'], ['text_muted', 'surface'], ['brand_contrast', 'brand'], ['accent_contrast', 'accent'], ['nav_text', 'nav_background']];

    public const MAX_UPLOAD_BYTES = 2 * 1024 * 1024;

    public const ALLOWED_MIME = ['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml', 'font/woff2', 'application/font-woff2'];

    public function __construct(
        private readonly BrandResolver $resolver,
        private readonly AuditRecorder $audit,
        private readonly Outbox $outbox,
    ) {}

    /** Store an uploaded brand asset privately; returns an upload id referenced by the kit payload. */
    public function storeUpload(UploadedFile $file, BrandAssetKind $kind): string
    {
        $mime = (string) $file->getMimeType();
        if (! in_array($mime, self::ALLOWED_MIME, true) || $file->getSize() > self::MAX_UPLOAD_BYTES) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Brand assets must be PNG, JPEG, WebP, SVG or WOFF2 and at most 2 MB.', ['mime' => $mime]);
        }
        $id = (string) Str::uuid7();
        $ext = match ($mime) {
            'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/svg+xml' => 'svg', default => 'woff2',
        };
        $contents = (string) file_get_contents($file->getRealPath());
        if ($ext === 'svg') {
            $contents = self::sanitizeSvg($contents);
        }
        Storage::disk('private')->put("brand-uploads/{$id}.{$ext}", $contents);
        Storage::disk('private')->put("brand-uploads/{$id}.json", json_encode(['kind' => $kind->value, 'mime' => $mime, 'ext' => $ext, 'name' => $file->getClientOriginalName()]));

        return $id;
    }

    /**
     * Create a kit from a validated payload and activate it.
     *
     * @param  array<string, mixed>  $data  app_name, app_short_name, tagline, legal_footer, support_*, theme{colors,dark,font,radius,density},
     *                                      assets{kind => upload id}, contrast_override_reason
     */
    public function createAndActivate(array $data, ?UserAccount $actor): BrandKit
    {
        $theme = array_replace_recursive(BrandKit::defaultTheme(), $data['theme'] ?? []);
        $failures = $this->contrastFailures($theme['colors']);
        if ($failures !== [] && empty($data['contrast_override_reason'])) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Some colour pairs fail WCAG AA contrast (4.5:1).',
                ['contrast_failures' => $failures], 'Adjust the colours or record a contrast override reason.');
        }

        return DB::transaction(function () use ($data, $theme, $actor, $failures) {
            $kit = new BrandKit([
                'app_name' => $data['app_name'], 'app_short_name' => $data['app_short_name'], 'tagline' => $data['tagline'] ?? null,
                'legal_footer' => $data['legal_footer'] ?? null, 'support_email' => $data['support_email'] ?? null,
                'support_phone' => $data['support_phone'] ?? null, 'support_url' => $data['support_url'] ?? null,
                'theme' => $theme, 'locale_defaults' => $data['locale_defaults'] ?? null,
                'version' => (int) (BrandKit::query()->max('version') ?? 0) + 1, 'imported_from' => $data['imported_from'] ?? null,
                'is_active' => false, 'created_by' => $actor?->id,
            ]);
            $kit->save();

            foreach ((array) ($data['assets'] ?? []) as $kindValue => $uploadId) {
                $kind = BrandAssetKind::tryFrom((string) $kindValue);
                if ($kind === null || $kind === BrandAssetKind::DERIVED_ICON || ! is_string($uploadId) || ! Str::isUuid($uploadId)) {
                    throw DomainException::of(ErrorCode::VALIDATION_FAILED, "Invalid brand asset `$kindValue`.");
                }
                $this->attachUpload($kit, $kind, $uploadId);
            }

            $this->activate($kit, $actor, $failures === [] ? null : (string) $data['contrast_override_reason']);

            return $kit->refresh();
        });
    }

    public function activate(BrandKit $kit, ?UserAccount $actor, ?string $contrastOverride = null): void
    {
        DB::transaction(function () use ($kit, $actor, $contrastOverride) {
            BrandKit::query()->where('is_active', true)->where('id', '<>', $kit->id)->update(['is_active' => false]);
            $this->generateDerivedAssets($kit);
            $checksum = $this->checksum($kit);
            $kit->forceFill(['is_active' => true, 'activated_at' => now(), 'activated_by' => $actor?->id, 'checksum' => $checksum])->save();
            $this->resolver->forget();
            Storage::disk('brand')->put("theme-{$checksum}.css", $this->themeCss(BrandResolver::fromKit($kit->refresh()->load('assets'))));
            $this->audit->record($actor, 'platform.brand.activated', 'brand_kit', $kit->id, null,
                ['app_name' => $kit->app_name, 'version' => $kit->version, 'checksum' => $checksum], $contrastOverride,
                $contrastOverride ? ['contrast_override' => true] : []);
            $this->outbox->publish('platform.brand.activated', 'brand_kit', $kit->id, ['checksum' => $checksum]);
        });
        $this->resolver->forget();
    }

    /** @param  array<string, string>  $colors @return array<int, array{pair: string, ratio: float}> */
    public function contrastFailures(array $colors): array
    {
        $out = [];
        foreach (self::COLOR_PAIRS as [$fg, $bg]) {
            if (! isset($colors[$fg], $colors[$bg])) {
                continue;
            }
            $ratio = self::contrastRatio($colors[$fg], $colors[$bg]);
            if ($ratio < 4.5) {
                $out[] = ['pair' => "$fg on $bg", 'ratio' => round($ratio, 2)];
            }
        }

        return $out;
    }

    public static function contrastRatio(string $a, string $b): float
    {
        $l1 = self::luminance($a);
        $l2 = self::luminance($b);

        return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
    }

    /** CSS custom properties for the active kit (light + optional dark). @param  array<string, mixed>  $profile */
    public function themeCss(array $profile): string
    {
        $theme = $profile['theme'];
        $vars = static function (array $colors): string {
            $lines = [];
            foreach ($colors as $name => $value) {
                if (is_string($value) && preg_match('/^#[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/', $value) === 1) {
                    $lines[] = '  --ssr-'.str_replace('_', '-', $name).': '.$value.';';
                }
            }

            return implode("\n", $lines);
        };
        $radius = ['none' => '0', 'small' => '0.25rem', 'medium' => '0.5rem', 'large' => '0.875rem'][$theme['radius'] ?? 'medium'] ?? '0.5rem';
        $density = ['compact' => '0.85', 'comfortable' => '1', 'spacious' => '1.15'][$theme['density'] ?? 'comfortable'] ?? '1';
        $font = match ($theme['font'] ?? 'system') {
            'inter' => '"Inter", ui-sans-serif, system-ui, sans-serif',
            'roboto' => '"Roboto", ui-sans-serif, system-ui, sans-serif',
            'custom' => '"BrandFont", ui-sans-serif, system-ui, sans-serif',
            default => 'ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Noto Sans", sans-serif',
        };
        $fontFace = isset($profile['assets']['font_file'])
            ? "@font-face { font-family: \"BrandFont\"; src: url(\"{$profile['assets']['font_file']}\") format(\"woff2\"); font-display: swap; }\n"
            : '';
        $dark = array_replace($theme['colors'], $theme['dark'] ?? []);

        return $fontFace.":root {\n".$vars($theme['colors'])."\n  --ssr-radius: {$radius};\n  --ssr-density: {$density};\n  --ssr-font: {$font};\n}\n"
            ."@media (prefers-color-scheme: dark) {\n  :root[data-theme-mode=\"auto\"] {\n".$vars($dark)."\n  }\n}\n"
            .":root[data-theme-mode=\"dark\"] {\n".$vars($dark)."\n}\n";
    }

    /** @param  array<string, mixed>  $profile @return array<string, mixed> */
    public function manifest(array $profile): array
    {
        $icons = [];
        foreach (self::ICON_SIZES as $size) {
            if (isset($profile['assets']["icon_{$size}"])) {
                $icons[] = ['src' => $profile['assets']["icon_{$size}"], 'sizes' => "{$size}x{$size}", 'type' => 'image/png', 'purpose' => 'any'];
            }
        }
        if (isset($profile['assets']['icon_maskable_512'])) {
            $icons[] = ['src' => $profile['assets']['icon_maskable_512'], 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'];
        }

        return [
            'id' => '/',
            'name' => $profile['app_name'],
            'short_name' => $profile['app_short_name'],
            'description' => $profile['tagline'] ?? $profile['app_name'],
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => $profile['theme']['colors']['surface'] ?? '#ffffff',
            'theme_color' => $profile['theme']['colors']['brand'] ?? '#0f2a47',
            'lang' => app()->getLocale(),
            'prefer_related_applications' => false,
            'icons' => $icons,
        ];
    }

    // ---------------------------------------------------------------------------------------

    private function attachUpload(BrandKit $kit, BrandAssetKind $kind, string $uploadId): void
    {
        $meta = json_decode((string) Storage::disk('private')->get("brand-uploads/{$uploadId}.json"), true);
        if (! is_array($meta) || ($meta['kind'] ?? null) !== $kind->value) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, "Upload $uploadId is not a `{$kind->value}` asset.");
        }
        $source = "brand-uploads/{$uploadId}.{$meta['ext']}";
        $target = "brand/{$kit->id}/{$kind->value}.{$meta['ext']}";
        Storage::disk('private')->copy($source, $target);
        $contents = (string) Storage::disk('private')->get($target);
        [$w, $h] = $meta['ext'] !== 'svg' && $meta['ext'] !== 'woff2' ? (@getimagesizefromstring($contents) ?: [null, null]) : [null, null];
        if ($kind === BrandAssetKind::PWA_ICON_SOURCE && ($w === null || $w < 512 || $w !== $h)) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'The PWA icon source must be a square raster image of at least 512 × 512 px.');
        }
        BrandAsset::query()->create([
            'brand_kit_id' => $kit->id, 'kind' => $kind, 'variant' => null, 'path' => $target, 'mime' => $meta['mime'],
            'checksum' => hash('sha256', $contents), 'width' => $w, 'height' => $h, 'bytes' => strlen($contents),
        ]);
    }

    private function generateDerivedAssets(BrandKit $kit): void
    {
        BrandAsset::query()->where('brand_kit_id', $kit->id)->where('kind', BrandAssetKind::DERIVED_ICON->value)->delete();
        $assets = BrandAsset::query()->where('brand_kit_id', $kit->id)->get()->keyBy(fn (BrandAsset $a) => $a->kind->value);
        $brand = $kit->theme['colors']['brand'] ?? '#0f2a47';
        $onBrand = $kit->theme['colors']['brand_contrast'] ?? '#ffffff';

        // Public copies of logos, fonts and backgrounds (content-hashed names).
        foreach ($assets as $asset) {
            $contents = (string) Storage::disk('private')->get($asset->path);
            $ext = pathinfo($asset->path, PATHINFO_EXTENSION);
            $public = "{$kit->id}/{$asset->kind->value}-".substr($asset->checksum, 0, 12).".{$ext}";
            Storage::disk('brand')->put($public, $contents);
            $asset->forceFill(['public_path' => $public])->save();
        }

        if (! extension_loaded('gd')) {
            return;
        }
        $source = $assets->get(BrandAssetKind::PWA_ICON_SOURCE->value);
        $sourceImage = $source !== null ? @imagecreatefromstring((string) Storage::disk('private')->get($source->path)) : false;
        foreach (self::ICON_SIZES as $size) {
            $this->storeIcon($kit, (string) $size, $this->renderIcon($sourceImage, $size, $brand, $onBrand, $kit->app_short_name, false));
        }
        $this->storeIcon($kit, 'maskable_512', $this->renderIcon($sourceImage, 512, $brand, $onBrand, $kit->app_short_name, true));
        $this->storeIcon($kit, 'apple_180', $this->renderIcon($sourceImage, 180, $brand, $onBrand, $kit->app_short_name, true));
        $this->storeIcon($kit, 'favicon_32', $this->renderIcon($sourceImage, 32, $brand, $onBrand, $kit->app_short_name, false));
    }

    private function renderIcon(\GdImage|false $source, int $size, string $bg, string $fg, string $shortName, bool $maskable): string
    {
        $canvas = imagecreatetruecolor($size, $size);
        imagesavealpha($canvas, true);
        [$r, $g, $b] = self::rgb($bg);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, $r, $g, $b));
        if ($source !== false) {
            // Maskable icons keep content inside the 80% safe zone.
            $inner = $maskable ? (int) round($size * 0.8) : $size;
            $offset = (int) (($size - $inner) / 2);
            imagecopyresampled($canvas, $source, $offset, $offset, 0, 0, $inner, $inner, imagesx($source), imagesy($source));
        } else {
            // Monogram fallback: brand initial scaled up from GD's bitmap font.
            $letter = strtoupper(mb_substr($shortName, 0, 1)) ?: 'S';
            $tile = imagecreatetruecolor(10, 16);
            imagefill($tile, 0, 0, imagecolorallocate($tile, $r, $g, $b));
            [$fr, $fg2, $fb] = self::rgb($fg);
            imagestring($tile, 5, 1, 0, $letter, imagecolorallocate($tile, $fr, $fg2, $fb));
            $glyph = (int) round($size * ($maskable ? 0.45 : 0.6));
            imagecopyresized($canvas, $tile, (int) (($size - $glyph * 10 / 16) / 2), (int) (($size - $glyph) / 2), 0, 0, (int) ($glyph * 10 / 16), $glyph, 10, 16);
            imagedestroy($tile);
        }
        ob_start();
        imagepng($canvas);
        imagedestroy($canvas);

        return (string) ob_get_clean();
    }

    private function storeIcon(BrandKit $kit, string $variant, string $png): void
    {
        $checksum = hash('sha256', $png);
        $public = "{$kit->id}/icon-{$variant}-".substr($checksum, 0, 12).'.png';
        Storage::disk('brand')->put($public, $png);
        $private = "brand/{$kit->id}/derived/icon-{$variant}.png";
        Storage::disk('private')->put($private, $png);
        $size = (int) (preg_match('/(\d+)$/', $variant, $m) ? $m[1] : 0);
        BrandAsset::query()->create([
            'brand_kit_id' => $kit->id, 'kind' => BrandAssetKind::DERIVED_ICON, 'variant' => $variant, 'path' => $private, 'public_path' => $public,
            'mime' => 'image/png', 'checksum' => $checksum, 'width' => $size, 'height' => $size, 'bytes' => strlen($png),
        ]);
    }

    private function checksum(BrandKit $kit): string
    {
        $assets = BrandAsset::query()->where('brand_kit_id', $kit->id)->orderBy('kind')->orderBy('variant')->pluck('checksum')->implode('|');

        return substr(hash('sha256', json_encode([$kit->app_name, $kit->app_short_name, $kit->tagline, $kit->theme, $kit->version]).$assets), 0, 16);
    }

    public static function sanitizeSvg(string $svg): string
    {
        $svg = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $svg) ?? '';
        $svg = preg_replace('#<foreignObject\b[^>]*>.*?</foreignObject>#is', '', $svg) ?? '';
        $svg = preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $svg) ?? '';
        $svg = preg_replace('/(href|xlink:href)\s*=\s*("|\')\s*javascript:[^"\']*\2/i', '', $svg) ?? '';

        return $svg;
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    private static function luminance(string $hex): float
    {
        $channels = array_map(static function (int $c): float {
            $s = $c / 255;

            return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
