<?php

declare(strict_types=1);

namespace App\Domains\Organization\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Put-away rule target type. */
enum PutawayAppliesTo: string implements SystemEnum
{
    use HasSystemEnum;

    case ITEM = 'item';
    case CATEGORY = 'category';
    case STORAGE_CATEGORY = 'storage_category';
}
