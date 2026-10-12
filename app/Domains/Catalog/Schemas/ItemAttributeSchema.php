<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Schemas;

use App\Domains\Catalog\Enums\AttributeDisplayType;
use App\Domains\Catalog\Enums\VariantCreationMode;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

final class ItemAttributeSchema extends TableSchema
{
    public static function table(): string
    {
        return 'item_attributes';
    }

    protected static function define(): array
    {
        return [
            'code' => Property::string(60)->requiredOnCreate()->trim()->pattern('/^[a-z0-9_]{1,60}$/')->unique()->label('Code'),
            'name' => Property::string(120)->required()->trim()->label('Name'),
            'display_type' => Property::enum(AttributeDisplayType::class)->default(AttributeDisplayType::SELECT->value)->label('Display'),
            'create_variant' => Property::enum(VariantCreationMode::class)->default(VariantCreationMode::ALWAYS->value)->label('Variant creation'),
            'sort_order' => Property::integer()->default(0)->min(0)->label('Sort order'),
            'values' => Property::arrayOf(ItemAttributeValueLineSchema::class)->nullable()->max(500)->virtual()->label('Values'),
        ];
    }
}
