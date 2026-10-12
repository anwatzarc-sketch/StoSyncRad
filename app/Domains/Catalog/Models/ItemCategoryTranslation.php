<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Models;

use App\Support\Eloquent\BaseModel;

/** Localized category name. */
final class ItemCategoryTranslation extends BaseModel
{
    protected $table = 'item_category_translations';

    protected $fillable = ['item_category_id', 'locale', 'name'];
}
