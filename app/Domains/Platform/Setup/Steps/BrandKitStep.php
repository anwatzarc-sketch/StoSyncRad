<?php

declare(strict_types=1);

namespace App\Domains\Platform\Setup\Steps;

use App\Domains\Platform\Enums\FontFamily;
use App\Domains\Platform\Enums\PlatformSetupStep;
use App\Domains\Platform\Enums\ThemeDensity;
use App\Domains\Platform\Enums\ThemeRadius;
use App\Domains\Platform\Models\BrandKit;
use App\Domains\Platform\Services\BrandKitService;
use App\Domains\Platform\Services\BrandResolver;
use App\Domains\Platform\Setup\SetupContext;
use App\Domains\Platform\Setup\SetupStepHandler;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;

/**
 * Step 4 — brand kit (Chapter 03 §3.5).
 * Payload: app_name?, app_short_name? (≤12; default: the product name), tagline?, legal_footer?, support_email?, support_phone?, support_url?,
 *          theme{colors{…}, dark{…}, font, radius, density}, assets{kind: upload id}, contrast_override_reason?
 */
final class BrandKitStep implements SetupStepHandler
{
    public function __construct(private readonly BrandKitService $brands, private readonly BrandResolver $resolver) {}

    public function step(): PlatformSetupStep
    {
        return PlatformSetupStep::BRAND_KIT;
    }

    public function handle(array $payload, SetupContext $context): array
    {
        // The product name is fixed (config/product.php); older bundles may still carry these keys.
        $name = trim((string) ($payload['app_name'] ?? '')) ?: (string) config('product.name');
        $short = trim((string) ($payload['app_short_name'] ?? '')) ?: (string) config('product.short_name');
        if (mb_strlen($name) > 80) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, '`app_name` must be at most 80 characters.', ['field' => 'app_name']);
        }
        if (mb_strlen($short) > 12) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, '`app_short_name` must be at most 12 characters.', ['field' => 'app_short_name']);
        }
        $theme = (array) ($payload['theme'] ?? []);
        foreach ((array) ($theme['colors'] ?? []) + (array) ($theme['dark'] ?? []) as $token => $value) {
            if (! array_key_exists($token, BrandKit::defaultTheme()['colors']) || ! is_string($value) || preg_match('/^#[0-9a-fA-F]{6}$/', $value) !== 1) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, "Theme colour `$token` must be a known token with a #RRGGBB value.", ['field' => "theme.colors.$token"]);
            }
        }
        foreach (['font' => FontFamily::class, 'radius' => ThemeRadius::class, 'density' => ThemeDensity::class] as $field => $enum) {
            if (isset($theme[$field]) && $enum::tryFromInput($theme[$field]) === null) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, "`theme.$field` must be one of: ".implode(', ', $enum::values()).'.',
                    ['field' => "theme.$field", 'allowed_values' => $enum::values(), 'received' => $theme[$field]]);
            }
        }
        $kit = $this->brands->createAndActivate([
            'app_name' => $name, 'app_short_name' => $short, 'tagline' => $payload['tagline'] ?? null, 'legal_footer' => $payload['legal_footer'] ?? null,
            'support_email' => $payload['support_email'] ?? null, 'support_phone' => $payload['support_phone'] ?? null, 'support_url' => $payload['support_url'] ?? null,
            'theme' => $theme, 'assets' => (array) ($payload['assets'] ?? []), 'contrast_override_reason' => $payload['contrast_override_reason'] ?? null,
            'imported_from' => $context->headless ? 'config_bundle' : 'setup_wizard',
        ], $context->actor);

        return ['brand_kit_id' => $kit->id, 'checksum' => $kit->checksum];
    }

    public function current(): array
    {
        return ['brand' => $this->resolver->publicProfile(), 'defaults' => BrandKit::defaultTheme(), 'color_pairs' => BrandKitService::COLOR_PAIRS];
    }
}
