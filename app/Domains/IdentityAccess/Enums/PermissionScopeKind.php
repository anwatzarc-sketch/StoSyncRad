<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** How a permission is scoped (Chapter 04 §4.4). */
enum PermissionScopeKind: string implements SystemEnum
{
    use HasSystemEnum;

    case GLOBAL = 'global';
    case BRANCH = 'branch';
    case WAREHOUSE = 'warehouse';
    case OWN_RECORDS = 'own_records';
}
