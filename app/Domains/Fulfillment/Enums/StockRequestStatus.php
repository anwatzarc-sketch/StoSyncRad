<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Enums;

use App\Support\Enums\HasStateTransitions;
use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\StateEnum;

/** Stock request lifecycle (Chapter 08 §8.6). */
enum StockRequestStatus: string implements StateEnum
{
    use HasStateTransitions;
    use HasSystemEnum;

    case DRAFT = 'draft';
    case SUBMITTED = 'submitted';
    case APPROVED = 'approved';
    case PARTIALLY_FULFILLED = 'partially_fulfilled';
    case FULFILLED = 'fulfilled';
    case REJECTED = 'rejected';
    case CANCELLED = 'cancelled';

    public static function transitions(): array
    {
        return [
            self::DRAFT->value => [self::SUBMITTED->value, self::CANCELLED->value],
            self::SUBMITTED->value => [self::APPROVED->value, self::REJECTED->value, self::DRAFT->value, self::CANCELLED->value],
            self::APPROVED->value => [self::PARTIALLY_FULFILLED->value, self::FULFILLED->value, self::CANCELLED->value],
            self::PARTIALLY_FULFILLED->value => [self::FULFILLED->value, self::CANCELLED->value],
            self::FULFILLED->value => [],
            self::REJECTED->value => [],
            self::CANCELLED->value => [],
        ];
    }
}
