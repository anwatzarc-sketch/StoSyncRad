<?php

declare(strict_types=1);

namespace App\Domains\Billing\Enums;

use App\Support\Enums\HasStateTransitions;
use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\StateEnum;

/** Customer payment confirmation (evidence) under finance review. Only verification records a payment. */
enum PaymentConfirmationStatus: string implements StateEnum
{
    use HasStateTransitions;
    use HasSystemEnum;

    case SUBMITTED = 'submitted';
    case INFO_REQUESTED = 'info_requested';
    case VERIFIED = 'verified';
    case REJECTED = 'rejected';
    case WITHDRAWN = 'withdrawn';

    public static function transitions(): array
    {
        return [
            self::SUBMITTED->value => [self::INFO_REQUESTED->value, self::VERIFIED->value, self::REJECTED->value, self::WITHDRAWN->value],
            self::INFO_REQUESTED->value => [self::SUBMITTED->value, self::REJECTED->value, self::WITHDRAWN->value],
            self::VERIFIED->value => [],
            self::REJECTED->value => [],
            self::WITHDRAWN->value => [],
        ];
    }
}
