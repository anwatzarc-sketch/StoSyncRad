<?php

declare(strict_types=1);

namespace App\Domains\ReportingEngine\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** PDF rendering engine. */
enum PdfRenderer: string implements SystemEnum
{
    use HasSystemEnum;

    case DOMPDF = 'dompdf';
    case CHROMIUM = 'chromium';
}
