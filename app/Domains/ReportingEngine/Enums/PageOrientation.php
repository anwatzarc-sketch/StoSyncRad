<?php

declare(strict_types=1);

namespace App\Domains\ReportingEngine\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** PDF page orientation. */
enum PageOrientation: string implements SystemEnum
{
    use HasSystemEnum;

    case PORTRAIT = 'portrait';
    case LANDSCAPE = 'landscape';
}
