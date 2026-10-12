<?php

declare(strict_types=1);

namespace App\Domains\Stocktaking\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Stock adjustment type. */
enum AdjustmentType: string implements SystemEnum
{
    use HasSystemEnum;

    case COUNT_VARIANCE = 'count_variance';
    case DAMAGE = 'damage';
    case LOSS = 'loss';
    case EXPIRY_WRITEOFF = 'expiry_writeoff';
    case FOUND = 'found';
    case DATA_CORRECTION = 'data_correction';
    case OPENING_CORRECTION = 'opening_correction';
    case SCRAP = 'scrap';
    case OTHER = 'other';
}
