<?php

declare(strict_types=1);

namespace App\Domains\Sales\Enums;

use App\Support\Enums\HasStateTransitions;
use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\StateEnum;

/** Quotation lifecycle: requested by the customer → priced and sent by staff → accepted (becomes a sales order), declined, revised, expired or cancelled. */
enum QuotationStatus: string implements StateEnum
{
    use HasStateTransitions;
    use HasSystemEnum;

    case REQUESTED = 'requested';
    case SENT = 'sent';
    case REVISION_REQUESTED = 'revision_requested';
    case ACCEPTED = 'accepted';
    case DECLINED = 'declined';
    case EXPIRED = 'expired';
    case CANCELLED = 'cancelled';

    public static function transitions(): array
    {
        return [
            self::REQUESTED->value => [self::SENT->value, self::CANCELLED->value],
            self::SENT->value => [self::ACCEPTED->value, self::DECLINED->value, self::REVISION_REQUESTED->value, self::EXPIRED->value, self::CANCELLED->value],
            self::REVISION_REQUESTED->value => [self::SENT->value, self::CANCELLED->value],
            self::ACCEPTED->value => [],
            self::DECLINED->value => [],
            self::EXPIRED->value => [self::SENT->value, self::CANCELLED->value],
            self::CANCELLED->value => [],
        ];
    }
}
