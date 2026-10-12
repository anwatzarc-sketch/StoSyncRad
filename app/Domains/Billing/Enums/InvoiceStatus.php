<?php

declare(strict_types=1);

namespace App\Domains\Billing\Enums;

use App\Support\Enums\HasStateTransitions;
use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\StateEnum;

/** Invoice lifecycle. Only finance payment allocations move an issued invoice to partially paid / paid. */
enum InvoiceStatus: string implements StateEnum
{
    use HasStateTransitions;
    use HasSystemEnum;

    case DRAFT = 'draft';
    case ISSUED = 'issued';
    case PARTIALLY_PAID = 'partially_paid';
    case PAID = 'paid';
    case VOID = 'void';

    public static function transitions(): array
    {
        return [
            self::DRAFT->value => [self::ISSUED->value, self::VOID->value],
            self::ISSUED->value => [self::PARTIALLY_PAID->value, self::PAID->value, self::VOID->value],
            self::PARTIALLY_PAID->value => [self::PAID->value, self::ISSUED->value],
            self::PAID->value => [self::PARTIALLY_PAID->value, self::ISSUED->value],
            self::VOID->value => [],
        ];
    }
}
