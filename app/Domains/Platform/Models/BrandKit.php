<?php

declare(strict_types=1);

namespace App\Domains\Platform\Models;

use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * White-label identity (Chapter 03 §3.5). Exactly one row is active.
 *
 * @property string $app_name
 * @property string $app_short_name
 * @property string|null $tagline
 * @property string|null $legal_footer
 * @property string|null $support_email
 * @property string|null $support_phone
 * @property string|null $support_url
 * @property array<string,mixed> $theme
 * @property array<string,mixed>|null $locale_defaults
 * @property int $version
 * @property string|null $checksum
 * @property bool $is_active
 */
final class BrandKit extends BaseModel
{
    protected $table = 'brand_kits';

    protected $fillable = [
        'app_name', 'app_short_name', 'tagline', 'legal_footer', 'support_email', 'support_phone', 'support_url',
        'theme', 'locale_defaults', 'version', 'checksum', 'imported_from', 'is_active', 'activated_at', 'activated_by',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'theme' => 'array',
            'locale_defaults' => 'array',
            'version' => 'integer',
            'is_active' => 'boolean',
            'activated_at' => 'immutable_datetime',
        ];
    }

    public function assets(): HasMany
    {
        return $this->hasMany(BrandAsset::class);
    }

    /** Default theme tokens used when a kit omits a token (WCAG AA verified pairs). */
    public static function defaultTheme(): array
    {
        return [
            'colors' => [
                'brand' => '#04326d', 'brand_contrast' => '#ffffff',
                'accent' => '#087a7b', 'accent_contrast' => '#ffffff',
                'surface' => '#ffffff', 'surface_muted' => '#f3f6f9',
                'text' => '#111827', 'text_muted' => '#4b5563',
                'border' => '#d9dee5', 'focus' => '#2563eb',
                'success' => '#15803d', 'warning' => '#b45309', 'danger' => '#b91c1c', 'info' => '#1d4ed8',
                'nav_background' => '#04326d', 'nav_text' => '#e8f1fb',
            ],
            'dark' => [
                'surface' => '#0b1220', 'surface_muted' => '#111a2b', 'text' => '#e5e7eb', 'text_muted' => '#9ca3af',
                'border' => '#273449', 'nav_background' => '#0b1220', 'nav_text' => '#e5edf5',
            ],
            'font' => 'system',
            'radius' => 'medium',
            'density' => 'comfortable',
        ];
    }
}
