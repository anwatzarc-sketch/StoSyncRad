<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Scope of a role assignment (Chapter 04 §4.6). */
enum AssignmentScopeType: string implements SystemEnum
{
    use HasSystemEnum;

    case COMPANY = 'company';
    case BRANCH = 'branch';
    case WAREHOUSE = 'warehouse';
}
