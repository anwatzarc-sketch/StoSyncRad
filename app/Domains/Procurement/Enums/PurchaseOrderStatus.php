<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Enums;

use App\Support\Enums\HasStateTransitions;
use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\StateEnum;

/** Purchase order lifecycle (Chapter 08 §8.2). */
enum PurchaseOrderStatus: string implements StateEnum
{
    use HasStateTransitions;
    use HasSystemEnum;

    case DRAFT = 'draft';
    case SUBMITTED = 'submitted';
    case APPROVED = 'approved';
    case SENT = 'sent';
    case PARTIALLY_RECEIVED = 'partially_received';
    case FULLY_RECEIVED = 'fully_received';
    case CLOSED = 'closed';
    case CANCELLED = 'cancelled';

    public static function transitions(): array
    {
        return [
            self::DRAFT->value => [self::SUBMITTED->value, self::CANCELLED->value],
            self::SUBMITTED->value => [self::APPROVED->value, self::DRAFT->value, self::CANCELLED->value],
            self::APPROVED->value => [self::SENT->value, self::SUBMITTED->value, self::PARTIALLY_RECEIVED->value, self::FULLY_RECEIVED->value, self::CANCELLED->value],
            self::SENT->value => [self::SUBMITTED->value, self::PARTIALLY_RECEIVED->value, self::FULLY_RECEIVED->value, self::CANCELLED->value],
            self::PARTIALLY_RECEIVED->value => [self::FULLY_RECEIVED->value, self::CLOSED->value],
            self::FULLY_RECEIVED->value => [self::CLOSED->value],
            self::CLOSED->value => [],
            self::CANCELLED->value => [],
        ];
    }
}
