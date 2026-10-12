<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Upload a product photo (multipart: `image` file, optional `is_primary`). */
final class ItemImageUploadSchema extends TableSchema
{
    public static function table(): string
    {
        return 'item_images';
    }

    protected static function define(): array
    {
        return [
            'image' => Property::any()->nullable()->virtual()->label('Photo', 'JPG, PNG or WebP.'),
            'is_primary' => Property::boolean()->nullable()->label('Primary photo', 'Shown first in the catalogue and the customer portal.'),
        ];
    }
}
