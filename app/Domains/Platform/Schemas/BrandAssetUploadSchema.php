<?php

declare(strict_types=1);

namespace App\Domains\Platform\Schemas;

use App\Domains\Platform\Enums\BrandAssetKind;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Upload a logo, icon or favicon (the image travels as the `file` part). */
final class BrandAssetUploadSchema extends TableSchema
{
    public static function table(): string
    {
        return 'brand_assets';
    }

    protected static function define(): array
    {
        return [
            'kind' => Property::enum(BrandAssetKind::class)->required()->virtual()->label('Asset'),
        ];
    }
}
