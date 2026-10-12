<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Models;

use App\Domains\Catalog\Enums\UomCategoryKind;
use App\Domains\Catalog\Schemas\UomCategorySchema;
use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Unit-of-measure category (unit, weight, volume…). */
final class UomCategory extends BaseModel
{
    protected $table = 'uom_categories';

    protected static ?string $schema = UomCategorySchema::class;

    protected function casts(): array
    {
        return [
            'kind' => UomCategoryKind::class,
        ];
    }

    public function units(): HasMany
    {
        return $this->hasMany(UnitOfMeasure::class, 'uom_category_id');
    }
}
