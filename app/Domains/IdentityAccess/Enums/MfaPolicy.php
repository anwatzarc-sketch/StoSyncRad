<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Deployment MFA policy (identity.mfa.policy). */
enum MfaPolicy: string implements SystemEnum
{
    use HasSystemEnum;

    case OFF = 'off';
    case OPTIONAL = 'optional';
    case REQUIRED_FOR_SENSITIVE = 'required_for_sensitive';
    case REQUIRED_FOR_ALL = 'required_for_all';
}
