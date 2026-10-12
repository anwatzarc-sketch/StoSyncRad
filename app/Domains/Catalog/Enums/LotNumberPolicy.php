<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Source of lot numbers. */
enum LotNumberPolicy: string implements SystemEnum
{
    use HasSystemEnum;

    case SUPPLIER_PROVIDED = 'supplier_provided';
    case GENERATED = 'generated';
    case EITHER = 'either';
}
