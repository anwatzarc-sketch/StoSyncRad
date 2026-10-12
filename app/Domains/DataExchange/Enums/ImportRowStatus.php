<?php

declare(strict_types=1);

namespace App\Domains\DataExchange\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Import row status. */
enum ImportRowStatus: string implements SystemEnum
{
    use HasSystemEnum;

    case VALID = 'valid';
    case INVALID = 'invalid';
    case IMPORTED = 'imported';
    case SKIPPED = 'skipped';
}
