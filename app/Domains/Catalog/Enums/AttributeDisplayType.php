<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** How a variant attribute is displayed. */
enum AttributeDisplayType: string implements SystemEnum
{
    use HasSystemEnum;

    case RADIO = 'radio';
    case SELECT = 'select';
    case COLOR = 'color';
    case TEXT = 'text';
}
