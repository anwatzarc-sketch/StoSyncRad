<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Quality check outcome. */
enum QcOutcome: string implements SystemEnum
{
    use HasSystemEnum;

    case PASS = 'pass';
    case FAIL = 'fail';
    case CONDITIONAL = 'conditional';
}
