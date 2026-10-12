<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Scope in which lot numbers are unique. */
enum LotUniquenessScope: string implements SystemEnum
{
    use HasSystemEnum;

    case ITEM = 'item';
    case ITEM_SUPPLIER = 'item_supplier';
}
