<?php

declare(strict_types=1);

namespace App\Support\Transactions;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

enum IdempotencyStatus: string implements SystemEnum
{
    use HasSystemEnum;

    case IN_PROGRESS = 'in_progress';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
}
