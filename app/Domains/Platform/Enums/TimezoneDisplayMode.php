<?php

declare(strict_types=1);

namespace App\Domains\Platform\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Which timezone dates are displayed in. */
enum TimezoneDisplayMode: string implements SystemEnum
{
    use HasSystemEnum;

    case COMPANY = 'company';
    case BRANCH = 'branch';
    case USER = 'user';
}
