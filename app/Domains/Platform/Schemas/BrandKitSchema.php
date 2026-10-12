<?php

declare(strict_types=1);

namespace App\Domains\Platform\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** New brand kit version (activated immediately). */
final class BrandKitSchema extends TableSchema
{
    public static function table(): string
    {
        return 'brand_kits';
    }

    protected static function define(): array
    {
        return [
            'app_name' => Property::string(80)->required()->trim()->virtual()->label('Application name'),
            'app_short_name' => Property::string(30)->required()->trim()->virtual()->label('Short name'),
            'tagline' => Property::string(190)->nullable()->trim()->virtual()->label('Tagline'),
            'legal_footer' => Property::text(1000)->nullable()->trim()->virtual()->label('Legal footer'),
            'support_email' => Property::email()->nullable()->virtual()->label('Support email'),
            'support_phone' => Property::phone()->nullable()->virtual()->label('Support phone'),
            'support_url' => Property::url()->nullable()->virtual()->label('Support site'),
            'theme' => Property::json()->nullable()->virtual()->label('Theme tokens'),
            'locale_defaults' => Property::json()->nullable()->virtual()->label('Locale defaults'),
            'assets' => Property::json()->nullable()->virtual()->label('Uploaded assets', 'Map of asset kind to upload id'),
            'contrast_override_reason' => Property::text(1000)->nullable()->trim()->virtual()->label('Contrast override reason'),
        ];
    }
}
