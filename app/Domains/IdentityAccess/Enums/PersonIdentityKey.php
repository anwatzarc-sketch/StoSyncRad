<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Attribute used to match an existing person on account creation. */
enum PersonIdentityKey: string implements SystemEnum
{
    use HasSystemEnum;

    case EMAIL = 'email';
    case EMPLOYEE_NUMBER = 'employee_number';
    case NATIONAL_ID = 'national_id';
    case PHONE = 'phone';
}
