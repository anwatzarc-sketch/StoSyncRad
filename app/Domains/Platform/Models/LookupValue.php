<?php

declare(strict_types=1);

namespace App\Domains\Platform\Models;

use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $lookup_id
 * @property string $code
 * @property string $label
 * @property int $sort_order
 * @property bool $is_active
 * @property bool $is_system
 * @property array<string,mixed>|null $metadata
 */
final class LookupValue extends BaseModel
{
    protected $table = 'lookup_values';

    protected $fillable = ['lookup_id', 'code', 'label', 'sort_order', 'is_active', 'is_system', 'metadata'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'is_active' => 'boolean', 'is_system' => 'boolean', 'metadata' => 'array'];
    }

    public function lookup(): BelongsTo
    {
        return $this->belongsTo(Lookup::class);
    }
}
