<?php

declare(strict_types=1);

namespace App\Domains\ReportingEngine\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** PDF page size. */
enum PageSize: string implements SystemEnum
{
    use HasSystemEnum;

    case A4 = 'a4';
    case A3 = 'a3';
    case LETTER = 'letter';
    case LEGAL = 'legal';
}
