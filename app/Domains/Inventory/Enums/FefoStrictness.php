<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** FEFO enforcement (stock.fefo.strictness). */
enum FefoStrictness: string implements SystemEnum
{
    use HasSystemEnum;

    case ENFORCE = 'enforce';
    case SUGGEST = 'suggest';
}
