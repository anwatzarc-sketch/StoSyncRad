<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Enums;

use App\Support\Enums\HasStateTransitions;
use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\StateEnum;

/** Purchase request lifecycle. */
enum PurchaseRequestStatus: string implements StateEnum
{
    use HasStateTransitions;
    use HasSystemEnum;

    case DRAFT = 'draft';
    case SUBMITTED = 'submitted';
    case APPROVED = 'approved';
    case PARTIALLY_ORDERED = 'partially_ordered';
    case ORDERED = 'ordered';
    case REJECTED = 'rejected';
    case CANCELLED = 'cancelled';

    public static function transitions(): array
    {
        return [
            self::DRAFT->value => [self::SUBMITTED->value, self::CANCELLED->value],
            self::SUBMITTED->value => [self::APPROVED->value, self::REJECTED->value, self::DRAFT->value, self::CANCELLED->value],
            self::APPROVED->value => [self::PARTIALLY_ORDERED->value, self::ORDERED->value, self::CANCELLED->value],
            self::PARTIALLY_ORDERED->value => [self::ORDERED->value],
            self::ORDERED->value => [],
            self::REJECTED->value => [],
            self::CANCELLED->value => [],
        ];
    }
}
