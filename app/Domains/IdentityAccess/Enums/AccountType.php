<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Login identity kind (Chapter 04 §4.1). */
enum AccountType: string implements SystemEnum
{
    use HasSystemEnum;

    case HUMAN = 'human';
    case SERVICE = 'service';
}
