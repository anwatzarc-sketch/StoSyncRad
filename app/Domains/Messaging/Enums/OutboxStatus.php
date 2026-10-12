<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

enum OutboxStatus: string implements SystemEnum
{
    use HasSystemEnum;

    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case PROCESSED = 'processed';
    case FAILED = 'failed';
    case DEAD = 'dead';
}
