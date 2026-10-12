<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Expiry tracking policy. */
enum ExpiryPolicy: string implements SystemEnum
{
    use HasSystemEnum;

    case NOT_TRACKED = 'not_tracked';
    case TRACKED_OPTIONAL = 'tracked_optional';
    case TRACKED_REQUIRED = 'tracked_required';
    case INHERIT_CATEGORY = 'inherit_category';
}
