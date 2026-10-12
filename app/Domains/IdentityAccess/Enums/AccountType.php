<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Login identity kind (Chapter 04 §4.1). Customer accounts belong to one customer partner and use only the Customer Portal. */
enum AccountType: string implements SystemEnum
{
    use HasSystemEnum;

    case HUMAN = 'human';
    case SERVICE = 'service';
    case CUSTOMER = 'customer';
}
