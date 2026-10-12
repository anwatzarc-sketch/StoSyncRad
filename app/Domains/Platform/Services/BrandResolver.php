<?php

declare(strict_types=1);

namespace App\Domains\Platform\Services;

use App\Domains\Platform\Enums\BrandAssetKind;
use App\Domains\Platform\Models\BrandKit;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves the identity shown on every surface (shell, login, manifest, PDFs, email). The product
 * (name, wordmark, tagline, vendor, logo, favicons, app icons) is fixed in config/product.php; the
 * customer contributes its company name and the active brand kit (theme, letterhead, support
 * contacts, legal footer). Nothing else hard-codes either. Cached; invalidated on brand activation.
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

        $kit = $this->cache->rememberForever(self::CACHE_KEY, function (): array {
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
        // The product identity and the company name are applied on top of the cached kit, so a
        // product upgrade or a company profile change shows without a cache flush.
        $product = self::product();

        return $this->memo = array_replace($kit, [
            'app_name' => $product['name'],
            'app_short_name' => $product['short_name'],
            'product' => $product,
            'company_name' => self::companyName(),
        ]);
    }

    /**
     * The product identity, identical for every customer.
     *
     * @return array<string, mixed>
     */
    public static function product(): array
    {
        $version = (string) config('product.assets_version', '1');

        return [
            'name' => (string) config('product.name'),
            'short_name' => (string) config('product.short_name'),
            'wordmark' => array_values((array) config('product.wordmark')),
            'tagline' => config('product.tagline'),
            'vendor' => config('product.vendor'),
            'vendor_url' => config('product.vendor_url'),
            'colors' => (array) config('product.colors'),
            'assets' => array_map(fn (string $path) => $path.'?v='.$version, (array) config('product.assets')),
        ];
    }

    /** Trading name of the customer (legal name as fallback); null before setup. */
    public static function companyName(): ?string
    {
        try {
            $row = Schema::hasTable('company_profile') ? DB::table('company_profile')->first(['trading_name', 'legal_name']) : null;
        } catch (QueryException) {
            $row = null;
        }
        $name = trim((string) ($row->trading_name ?? '')) ?: trim((string) ($row->legal_name ?? ''));

        return $name === '' ? null : $name;
    }

    public function forget(): void
    {
        $this->memo = null;
        $this->cache->forget(self::CACHE_KEY);
    }

    /** @return array<string, mixed> */
    public static function neutral(): array
    {
        $product = self::product();

        return [
            'id' => null,
            'app_name' => $product['name'],
            'app_short_name' => $product['short_name'],
            'product' => $product,
            'tagline' => null,
            'legal_footer' => null,
            'support_email' => null,
            'support_phone' => null,
            'support_url' => null,
            'version' => 0,
            'checksum' => 'neutral-p'.config('product.assets_version', '1'),
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
        $product = self::product();

        return [
            'id' => $kit->id,
            'app_name' => $product['name'],
            'app_short_name' => $product['short_name'],
            'product' => $product,
            'tagline' => $kit->tagline,
            'legal_footer' => $kit->legal_footer,
            'support_email' => $kit->support_email,
            'support_phone' => $kit->support_phone,
            'support_url' => $kit->support_url,
            'version' => $kit->version,
            'checksum' => ($kit->checksum ?? (string) $kit->version).'-p'.config('product.assets_version', '1'),
            'theme' => $theme,
            'assets' => $assets,
        ];
    }
}
