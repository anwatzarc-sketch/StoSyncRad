<?php

declare(strict_types=1);

namespace App\Domains\Organization\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

enum SequenceResetPolicy: string implements SystemEnum
{
    use HasSystemEnum;

    case NEVER = 'never';
    case YEARLY = 'yearly';
    case MONTHLY = 'monthly';
}
