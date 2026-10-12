<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Models;

use App\Domains\Catalog\Enums\UomStatus;
use App\Domains\Catalog\Schemas\UnitOfMeasureSchema;
use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Unit of measure. Conversion across categories only via item-specific conversions. */
final class UnitOfMeasure extends BaseModel
{
    protected $table = 'units_of_measure';

    protected static ?string $schema = UnitOfMeasureSchema::class;

    protected function casts(): array
    {
        return [
            'status' => UomStatus::class,
            'rounding_precision' => 'integer',
            'is_reference' => 'boolean',
            'is_whole_number' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(UomCategory::class, 'uom_category_id');
    }
}
