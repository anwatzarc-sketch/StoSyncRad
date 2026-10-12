<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Stock tracking mode. */
enum TrackingMode: string implements SystemEnum
{
    use HasSystemEnum;

    case NONE = 'none';
    case LOT = 'lot';
    case SERIAL = 'serial';

    public function requiresLot(): bool
    {
        return $this === self::LOT;
    }

    public function requiresSerial(): bool
    {
        return $this === self::SERIAL;
    }
}
