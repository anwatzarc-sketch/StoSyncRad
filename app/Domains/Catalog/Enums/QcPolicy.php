<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Receiving quality-control policy. */
enum QcPolicy: string implements SystemEnum
{
    use HasSystemEnum;

    case NONE = 'none';
    case INSPECT_ON_RECEIPT = 'inspect_on_receipt';
    case QUARANTINE_ON_RECEIPT = 'quarantine_on_receipt';
    case INHERIT_CATEGORY = 'inherit_category';
}
