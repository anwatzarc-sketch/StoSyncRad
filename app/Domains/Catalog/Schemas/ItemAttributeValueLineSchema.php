<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

final class ItemAttributeValueLineSchema extends TableSchema
{
    public static function table(): string
    {
        return 'item_attribute_values';
    }

    protected static function define(): array
    {
        return [
            'id' => Property::uuid()->nullable()->virtual(),
            'code' => Property::string(60)->required()->trim()->pattern('/^[a-z0-9_-]{1,60}$/')->label('Code'),
            'name' => Property::string(120)->required()->trim()->label('Name'),
            'html_color' => Property::string(9)->nullable()->pattern('/^#[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/')->label('Color'),
            'sort_order' => Property::integer()->default(0)->min(0)->label('Sort order'),
        ];
    }
}
