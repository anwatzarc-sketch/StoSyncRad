<?php

declare(strict_types=1);

namespace App\Domains\Organization\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

final class StorageCategorySchema extends TableSchema
{
    public static function table(): string
    {
        return 'storage_categories';
    }

    protected static function define(): array
    {
        return [
            'code' => Property::string(40)->requiredOnCreate()->trim()->pattern('/^[a-z0-9_]{2,40}$/')->unique()->label('Code'),
            'name' => Property::string(120)->required()->trim()->label('Name'),
            'constraints' => Property::json()->nullable()->label('Handling constraints'),
            'is_active' => Property::boolean()->default(true)->label('Active'),
        ];
    }
}
