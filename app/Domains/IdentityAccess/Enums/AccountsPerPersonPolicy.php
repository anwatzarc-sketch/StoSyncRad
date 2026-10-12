<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Corporate accounts-per-person policy (identity.accounts_per_person). */
enum AccountsPerPersonPolicy: string implements SystemEnum
{
    use HasSystemEnum;

    case SINGLE = 'single';
    case MULTIPLE = 'multiple';
}
