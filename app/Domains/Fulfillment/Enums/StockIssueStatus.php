<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Enums;

use App\Support\Enums\HasStateTransitions;
use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\StateEnum;

/** Stock issue lifecycle. */
enum StockIssueStatus: string implements StateEnum
{
    use HasStateTransitions;
    use HasSystemEnum;

    case DRAFT = 'draft';
    case PENDING_APPROVAL = 'pending_approval';
    case APPROVED = 'approved';
    case POSTED = 'posted';
    case CANCELLED = 'cancelled';

    public static function transitions(): array
    {
        return [
            self::DRAFT->value => [self::PENDING_APPROVAL->value, self::APPROVED->value, self::POSTED->value, self::CANCELLED->value],
            self::PENDING_APPROVAL->value => [self::APPROVED->value, self::DRAFT->value, self::CANCELLED->value],
            self::APPROVED->value => [self::POSTED->value, self::CANCELLED->value],
            self::POSTED->value => [],
            self::CANCELLED->value => [],
        ];
    }
}
