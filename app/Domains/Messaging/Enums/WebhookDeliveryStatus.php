<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Webhook delivery status. */
enum WebhookDeliveryStatus: string implements SystemEnum
{
    use HasSystemEnum;

    case PENDING = 'pending';
    case DELIVERED = 'delivered';
    case FAILED = 'failed';
    case DEAD = 'dead';
}
