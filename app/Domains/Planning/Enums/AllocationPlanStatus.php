<?php

declare(strict_types=1);

namespace App\Domains\Planning\Enums;

use App\Support\Enums\HasStateTransitions;
use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\StateEnum;

/** Allocation plan lifecycle (Chapter 08 §8.1). */
enum AllocationPlanStatus: string implements StateEnum
{
    use HasStateTransitions;
    use HasSystemEnum;

    case DRAFT = 'draft';
    case PENDING_APPROVAL = 'pending_approval';
    case APPROVED = 'approved';
    case RELEASED = 'released';
    case CANCELLED = 'cancelled';

    public static function transitions(): array
    {
        return [
            self::DRAFT->value => [self::PENDING_APPROVAL->value, self::CANCELLED->value],
            self::PENDING_APPROVAL->value => [self::APPROVED->value, self::DRAFT->value, self::CANCELLED->value],
            self::APPROVED->value => [self::RELEASED->value, self::CANCELLED->value],
            self::RELEASED->value => [],
            self::CANCELLED->value => [],
        ];
    }
}
