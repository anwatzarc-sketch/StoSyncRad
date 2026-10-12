<?php

declare(strict_types=1);

namespace App\Domains\Organization\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

enum SequenceScope: string implements SystemEnum
{
    use HasSystemEnum;

    case GLOBAL = 'global';
    case PER_YEAR = 'per_year';
    case PER_BRANCH = 'per_branch';
    case PER_WAREHOUSE = 'per_warehouse';
}
