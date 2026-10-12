<?php

declare(strict_types=1);

namespace App\Domains\Receiving\Enums;

use App\Support\Enums\HasStateTransitions;
use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\StateEnum;

/** Goods receipt lifecycle (Chapter 08 §8.3). */
enum GoodsReceiptStatus: string implements StateEnum
{
    use HasStateTransitions;
    use HasSystemEnum;

    case DRAFT = 'draft';
    case IN_INSPECTION = 'in_inspection';
    case PENDING_APPROVAL = 'pending_approval';
    case APPROVED = 'approved';
    case POSTED = 'posted';
    case CANCELLED = 'cancelled';

    public static function transitions(): array
    {
        return [
            self::DRAFT->value => [self::IN_INSPECTION->value, self::PENDING_APPROVAL->value, self::APPROVED->value, self::POSTED->value, self::CANCELLED->value],
            self::IN_INSPECTION->value => [self::PENDING_APPROVAL->value, self::APPROVED->value, self::POSTED->value, self::DRAFT->value, self::CANCELLED->value],
            self::PENDING_APPROVAL->value => [self::APPROVED->value, self::DRAFT->value, self::CANCELLED->value],
            self::APPROVED->value => [self::POSTED->value, self::CANCELLED->value],
            self::POSTED->value => [],
            self::CANCELLED->value => [],
        ];
    }
}
