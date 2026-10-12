<?php

declare(strict_types=1);

namespace App\Domains\Organization\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Storage hierarchy level. */
enum StorageLocationLevel: string implements SystemEnum
{
    use HasSystemEnum;

    case ZONE = 'zone';
    case AISLE = 'aisle';
    case RACK = 'rack';
    case SHELF = 'shelf';
    case BIN = 'bin';
    case AREA = 'area';
}
