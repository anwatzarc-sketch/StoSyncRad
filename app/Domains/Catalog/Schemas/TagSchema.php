<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

final class TagSchema extends TableSchema
{
    public static function table(): string
    {
        return 'tags';
    }

    protected static function define(): array
    {
        return [
            'code' => Property::string(60)->requiredOnCreate()->trim()->pattern('/^[a-z0-9_-]{1,60}$/')->unique()->searchable()->label('Code'),
            'name' => Property::string(120)->required()->trim()->searchable()->label('Name'),
            'color' => Property::string(9)->nullable()->pattern('/^#[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/')->label('Color'),
            'is_active' => Property::boolean()->default(true)->label('Active'),
        ];
    }
}
