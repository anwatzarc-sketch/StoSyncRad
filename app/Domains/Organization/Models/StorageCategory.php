<?php

declare(strict_types=1);

namespace App\Domains\Organization\Models;

use App\Domains\Organization\Schemas\StorageCategorySchema;
use App\Support\Eloquent\BaseModel;

/**
 * Handling/capacity class of storage (cold chain, hazardous, bulky…).
 *
 * @property string $code
 * @property string $name
 * @property array<string,mixed>|null $constraints
 * @property bool $is_active
 */
final class StorageCategory extends BaseModel
{
    protected $table = 'storage_categories';

    protected static ?string $schema = StorageCategorySchema::class;

    protected function casts(): array
    {
        return ['constraints' => 'array', 'is_active' => 'boolean'];
    }
}
