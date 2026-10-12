<?php

declare(strict_types=1);

namespace App\Support\Transactions;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

enum IdempotencyScopeType: string implements SystemEnum
{
    use HasSystemEnum;

    case ACCOUNT = 'account';
    case DOCUMENT = 'document';
    case API_CLIENT = 'api_client';
}
