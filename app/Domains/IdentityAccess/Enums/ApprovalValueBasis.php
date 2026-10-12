<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Value basis for approval tiers (Chapter 04 §4.8). */
enum ApprovalValueBasis: string implements SystemEnum
{
    use HasSystemEnum;

    case DOCUMENT_TOTAL = 'document_total';
    case LINE_QUANTITY = 'line_quantity';
    case COST_VALUE = 'cost_value';
}
