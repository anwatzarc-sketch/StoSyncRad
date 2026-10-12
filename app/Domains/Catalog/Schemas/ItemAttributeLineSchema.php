<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Schemas;

use App\Domains\Catalog\Models\ItemAttribute;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

final class ItemAttributeLineSchema extends TableSchema
{
    public static function table(): string
    {
        return 'item_attribute_lines';
    }

    protected static function define(): array
    {
        return [
            'item_attribute_id' => Property::uuid()->required()->references(ItemAttribute::class)->label('Attribute'),
            'value_ids' => Property::arrayOf(Property::uuid())->required()->min(1)->max(100)->label('Allowed values'),
        ];
    }
}
