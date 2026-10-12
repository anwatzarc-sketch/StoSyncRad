<?php

declare(strict_types=1);

namespace App\Domains\Transfers\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Authorized discrepancy outcome; each posts a traceable movement. */
enum DiscrepancyResolution: string implements SystemEnum
{
    use HasSystemEnum;

    case RECEIVE_ACCEPTED = 'receive_accepted';
    case KEEP_IN_TRANSIT = 'keep_in_transit';
    case RECORD_DAMAGE = 'record_damage';
    case LOSS_ADJUSTMENT = 'loss_adjustment';
    case RETURN_TO_SOURCE = 'return_to_source';
    case DATA_CORRECTION_REVERSAL = 'data_correction_reversal';
}
