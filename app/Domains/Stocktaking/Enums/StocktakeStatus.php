<?php

declare(strict_types=1);

namespace App\Domains\Stocktaking\Enums;

use App\Support\Enums\HasStateTransitions;
use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\StateEnum;

/** Stocktake lifecycle (Chapter 08 §8.10). */
enum StocktakeStatus: string implements StateEnum
{
    use HasStateTransitions;
    use HasSystemEnum;

    case DRAFT = 'draft';
    case SCHEDULED = 'scheduled';
    case COUNTING = 'counting';
    case RECOUNT_REQUIRED = 'recount_required';
    case REVIEW = 'review';
    case PENDING_APPROVAL = 'pending_approval';
    case APPROVED = 'approved';
    case POSTED = 'posted';
    case CANCELLED = 'cancelled';

    public static function transitions(): array
    {
        return [
            self::DRAFT->value => [self::SCHEDULED->value, self::COUNTING->value, self::CANCELLED->value],
            self::SCHEDULED->value => [self::COUNTING->value, self::CANCELLED->value],
            self::COUNTING->value => [self::RECOUNT_REQUIRED->value, self::REVIEW->value, self::CANCELLED->value],
            self::RECOUNT_REQUIRED->value => [self::COUNTING->value, self::REVIEW->value, self::CANCELLED->value],
            self::REVIEW->value => [self::PENDING_APPROVAL->value, self::APPROVED->value, self::RECOUNT_REQUIRED->value, self::CANCELLED->value],
            self::PENDING_APPROVAL->value => [self::APPROVED->value, self::REVIEW->value, self::CANCELLED->value],
            self::APPROVED->value => [self::POSTED->value],
            self::POSTED->value => [],
            self::CANCELLED->value => [],
        ];
    }
}
