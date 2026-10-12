<?php

declare(strict_types=1);

namespace App\Domains\Platform\Models;

use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Admin-editable list whose values carry no code semantics (Chapter 03 §3.4).
 *
 * @property string $code
 * @property string $label
 * @property string|null $description
 * @property bool $is_system
 */
final class Lookup extends BaseModel
{
    protected $table = 'lookups';

    protected $fillable = ['code', 'label', 'description', 'is_system'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    public function values(): HasMany
    {
        return $this->hasMany(LookupValue::class)->orderBy('sort_order')->orderBy('label');
    }
}
