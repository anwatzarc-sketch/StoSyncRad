<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Models;

use App\Domains\Catalog\Schemas\TagSchema;
use App\Support\Eloquent\BaseModel;

/** Cross-cutting product tag. */
final class Tag extends BaseModel
{
    protected $table = 'tags';

    protected static ?string $schema = TagSchema::class;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
