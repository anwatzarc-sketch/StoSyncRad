<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Answer type of one QC checklist check. */
enum QcCheckType: string implements SystemEnum
{
    use HasSystemEnum;

    case BOOLEAN = 'boolean';
    case NUMERIC = 'numeric';
    case TEXT = 'text';
    case CHOICE = 'choice';
}
