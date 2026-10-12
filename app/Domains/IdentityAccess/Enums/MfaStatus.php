<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** MFA enrollment state. */
enum MfaStatus: string implements SystemEnum
{
    use HasSystemEnum;

    case NOT_ENROLLED = 'not_enrolled';
    case ENROLLED = 'enrolled';
    case REQUIRED_PENDING = 'required_pending';
}
