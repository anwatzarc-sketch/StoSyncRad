<?php

declare(strict_types=1);

namespace App\Domains\DataExchange\Enums;

use App\Support\Enums\HasStateTransitions;
use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\StateEnum;

/** Import batch lifecycle (Chapter 11 §11.9). */
enum ImportBatchStatus: string implements StateEnum
{
    use HasStateTransitions;
    use HasSystemEnum;

    case UPLOADED = 'uploaded';
    case VALIDATING = 'validating';
    case PREVIEWED = 'previewed';
    case CONFIRMED = 'confirmed';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case COMPLETED_WITH_ERRORS = 'completed_with_errors';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';

    public static function transitions(): array
    {
        return [
            self::UPLOADED->value => [self::VALIDATING->value, self::CANCELLED->value],
            self::VALIDATING->value => [self::PREVIEWED->value, self::FAILED->value],
            self::PREVIEWED->value => [self::CONFIRMED->value, self::CANCELLED->value],
            self::CONFIRMED->value => [self::PROCESSING->value],
            self::PROCESSING->value => [self::COMPLETED->value, self::COMPLETED_WITH_ERRORS->value, self::FAILED->value],
            self::COMPLETED->value => [],
            self::COMPLETED_WITH_ERRORS->value => [],
            self::FAILED->value => [],
            self::CANCELLED->value => [],
        ];
    }
}
