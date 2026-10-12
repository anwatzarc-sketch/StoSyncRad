<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Separation-of-duties enforcement level (Chapter 04 §4.7). */
enum SodRuleType: string implements SystemEnum
{
    use HasSystemEnum;

    case DIFFERENT_ACTOR_REQUIRED = 'different_actor_required';
    case DIFFERENT_ACTOR_RECOMMENDED = 'different_actor_recommended';
    case NONE = 'none';
}
