<?php

declare(strict_types=1);

namespace App\Domains\Transfers\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Which side of a transfer may perform an action. */
enum TransferSide: string implements SystemEnum
{
    use HasSystemEnum;

    case SOURCE = 'source';
    case DESTINATION = 'destination';
    case EITHER = 'either';
    case BOTH = 'both';
}
