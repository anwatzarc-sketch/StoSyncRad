<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Enums;

use App\Support\Enums\HasStateTransitions;
use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\StateEnum;

/** Internal return lifecycle. */
enum InternalReturnStatus: string implements StateEnum
{
    use HasStateTransitions;
    use HasSystemEnum;

    case DRAFT = 'draft';
    case IN_INSPECTION = 'in_inspection';
    case APPROVED = 'approved';
    case POSTED = 'posted';
    case CANCELLED = 'cancelled';

    public static function transitions(): array
    {
        return [
            self::DRAFT->value => [self::IN_INSPECTION->value, self::APPROVED->value, self::CANCELLED->value],
            self::IN_INSPECTION->value => [self::APPROVED->value, self::CANCELLED->value],
            self::APPROVED->value => [self::POSTED->value, self::CANCELLED->value],
            self::POSTED->value => [],
            self::CANCELLED->value => [],
        ];
    }
}
