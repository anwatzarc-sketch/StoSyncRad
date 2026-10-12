<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Unit-of-measure category kind. */
enum UomCategoryKind: string implements SystemEnum
{
    use HasSystemEnum;

    case UNIT = 'unit';
    case WEIGHT = 'weight';
    case VOLUME = 'volume';
    case LENGTH = 'length';
    case AREA = 'area';
    case TIME = 'time';
    case PACKAGING = 'packaging';
    case CUSTOM = 'custom';
}
