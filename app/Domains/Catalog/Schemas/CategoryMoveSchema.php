<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Schemas;

use App\Domains\Catalog\Models\ItemCategory;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Move a category (and its subtree) under another parent. */
final class CategoryMoveSchema extends TableSchema
{
    public static function table(): string
    {
        return 'item_categories';
    }

    protected static function define(): array
    {
        return [
            'parent_id' => Property::uuid()->nullable()->references(ItemCategory::class)->virtual()->label('New parent (blank = top level)'),
            'reason' => Property::text(2000)->nullable()->trim()->virtual()->label('Reason'),
        ];
    }
}
