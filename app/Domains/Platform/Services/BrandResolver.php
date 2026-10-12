<?php

declare(strict_types=1);

namespace App\Domains\Platform\Services;

use App\Domains\Platform\Enums\BrandAssetKind;
use App\Domains\Platform\Models\BrandKit;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves the active brand kit for every surface (shell, login, manifest, PDFs, email) so no
 * brand string is hard-coded (Chapter 03 §3.5). Before setup completes a neutral identity is
 * returned. Cached; invalidated on brand activation.
 */
final class BrandResolver
{
    public const CACHE_KEY = 'platform.brand.active';

    /** @var array<string, mixed>|null */
    private ?array $memo = null;

    public function __construct(private readonly Cache $cache) {}

    /** @return array<string, mixed> */
    public function publicProfile(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        return $this->memo = $this->cache->rememberForever(self::CACHE_KEY, function (): array {
            $kit = null;
            try {
                if (Schema::hasTable('brand_kits')) {
                    $kit = BrandKit::query()->with('assets')->where('is_active', true)->first();
                }
            } catch (QueryException) {
                $kit = null;
            }

            return $kit === null ? self::neutral() : self::fromKit($kit);
        });
    }

    public function forget(): void
    {
        $this->memo = null;
        $this->cache->forget(self::CACHE_KEY);
    }

    /** @return array<string, mixed> */
    public static function neutral(): array
    {
        return [
            'id' => null,
            'app_name' => 'Stock Management',
            'app_short_name' => 'Stock',
            'tagline' => null,
            'legal_footer' => null,
            'support_email' => null,
            'support_phone' => null,
            'support_url' => null,
            'version' => 0,
            'checksum' => 'neutral',
            'theme' => BrandKit::defaultTheme(),
            'assets' => [],
        ];
    }

    /** @return array<string, mixed> */
    public static function fromKit(BrandKit $kit): array
    {
        $assets = [];
        foreach ($kit->assets as $asset) {
            if ($asset->public_path === null) {
                continue;
            }
            $key = $asset->kind === BrandAssetKind::DERIVED_ICON ? 'icon_'.$asset->variant : $asset->kind->value;
            $assets[$key] = '/brand/'.$asset->public_path;
        }
        $theme = array_replace_recursive(BrandKit::defaultTheme(), $kit->theme ?? []);

        return [
            'id' => $kit->id,
            'app_name' => $kit->app_name,
            'app_short_name' => $kit->app_short_name,
            'tagline' => $kit->tagline,
            'legal_footer' => $kit->legal_footer,
            'support_email' => $kit->support_email,
            'support_phone' => $kit->support_phone,
            'support_url' => $kit->support_url,
            'version' => $kit->version,
            'checksum' => $kit->checksum ?? (string) $kit->version,
            'theme' => $theme,
            'assets' => $assets,
        ];
    }
}
