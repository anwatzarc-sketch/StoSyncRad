<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Schemas;

use App\Domains\Catalog\Enums\UomCategoryKind;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

final class UomCategorySchema extends TableSchema
{
    public static function table(): string
    {
        return 'uom_categories';
    }

    protected static function define(): array
    {
        return [
            'code' => Property::string(40)->requiredOnCreate()->trim()->pattern('/^[a-z0-9_]{2,40}$/')->unique()->label('Code'),
            'name' => Property::string(120)->required()->trim()->label('Name'),
            'kind' => Property::enum(UomCategoryKind::class)->required()->label('Kind'),
        ];
    }
}
