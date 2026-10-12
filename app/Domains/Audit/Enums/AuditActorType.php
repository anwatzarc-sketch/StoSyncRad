<?php

declare(strict_types=1);

namespace App\Domains\Audit\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

enum AuditActorType: string implements SystemEnum
{
    use HasSystemEnum;

    case ACCOUNT = 'account';
    case SYSTEM = 'system';
    case INTEGRATION = 'integration';
    case SETUP = 'setup';
}
