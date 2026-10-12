<?php

declare(strict_types=1);

namespace App\Domains\Organization\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Purpose of a storage location. */
enum StorageLocationUsage: string implements SystemEnum
{
    use HasSystemEnum;

    case STORAGE = 'storage';
    case RECEIVING = 'receiving';
    case DISPATCH = 'dispatch';
    case QUARANTINE = 'quarantine';
    case DAMAGED = 'damaged';
    case SCRAP = 'scrap';
    case RETURNS = 'returns';
    case PACKING = 'packing';
    case CROSS_DOCK = 'cross_dock';
    case STAGING = 'staging';
}
