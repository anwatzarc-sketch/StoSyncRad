<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Sensitivity tier driving step-up MFA and audit (Chapter 04 §4.4). */
enum PermissionSensitivity: string implements SystemEnum
{
    use HasSystemEnum;

    case NORMAL = 'normal';
    case ELEVATED = 'elevated';
    case CRITICAL = 'critical';
}
