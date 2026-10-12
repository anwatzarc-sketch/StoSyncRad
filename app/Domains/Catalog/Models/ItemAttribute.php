<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Models;

use App\Domains\Catalog\Enums\AttributeDisplayType;
use App\Domains\Catalog\Enums\VariantCreationMode;
use App\Domains\Catalog\Schemas\ItemAttributeSchema;
use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Variant attribute (size, colour…). */
final class ItemAttribute extends BaseModel
{
    protected $table = 'item_attributes';

    protected static ?string $schema = ItemAttributeSchema::class;

    protected function casts(): array
    {
        return [
            'display_type' => AttributeDisplayType::class,
            'create_variant' => VariantCreationMode::class,
            'sort_order' => 'integer',
        ];
    }

    public function values(): HasMany
    {
        return $this->hasMany(ItemAttributeValue::class, 'item_attribute_id');
    }
}
