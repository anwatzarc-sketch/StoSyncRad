<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** ABC classification. */
enum AbcClass: string implements SystemEnum
{
    use HasSystemEnum;

    case A = 'a';
    case B = 'b';
    case C = 'c';
    case UNCLASSIFIED = 'unclassified';
}
