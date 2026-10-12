<?php

declare(strict_types=1);

namespace App\Domains\ReportingEngine\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Execution semaphore pool a report run occupies (Chapter 10 §10.5). */
enum ReportSlotKind: string implements SystemEnum
{
    use HasSystemEnum;

    case INTERACTIVE = 'interactive';
    case EXPORT = 'export';
}
