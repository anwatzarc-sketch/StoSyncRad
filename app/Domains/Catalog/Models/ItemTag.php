<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Str;

/** UUID-keyed pivot between items and tags. */
final class ItemTag extends Pivot
{
    use HasUuids;

    protected $table = 'item_tags';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }
}
