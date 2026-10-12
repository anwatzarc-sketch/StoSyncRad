<?php

declare(strict_types=1);

namespace App\Domains\ReportingEngine\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Report definition visibility. */
enum ReportVisibility: string implements SystemEnum
{
    use HasSystemEnum;

    case PRIVATE = 'private';
    case SHARED_ROLES = 'shared_roles';
    case SHARED_COMPANY = 'shared_company';
}
