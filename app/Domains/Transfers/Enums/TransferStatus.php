<?php

declare(strict_types=1);

namespace App\Domains\Transfers\Enums;

use App\Support\Enums\HasStateTransitions;
use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\StateEnum;

/** Transfer lifecycle; in-flight states are derived from dispatch/receipt events (Chapter 08 §8.4). */
enum TransferStatus: string implements StateEnum
{
    use HasStateTransitions;
    use HasSystemEnum;

    case DRAFT = 'draft';
    case SUBMITTED = 'submitted';
    case APPROVED = 'approved';
    case PICKING = 'picking';
    case PARTIALLY_DISPATCHED = 'partially_dispatched';
    case DISPATCHED = 'dispatched';
    case IN_TRANSIT = 'in_transit';
    case PARTIALLY_RECEIVED = 'partially_received';
    case RECEIVED = 'received';
    case DISCREPANCY_REVIEW = 'discrepancy_review';
    case CLOSED = 'closed';
    case CANCELLED = 'cancelled';

    public static function transitions(): array
    {
        return [
            self::DRAFT->value => [self::SUBMITTED->value, self::CANCELLED->value],
            self::SUBMITTED->value => [self::APPROVED->value, self::DRAFT->value, self::CANCELLED->value],
            self::APPROVED->value => [self::PICKING->value, self::PARTIALLY_DISPATCHED->value, self::DISPATCHED->value, self::IN_TRANSIT->value, self::CANCELLED->value],
            self::PICKING->value => [self::APPROVED->value, self::PARTIALLY_DISPATCHED->value, self::DISPATCHED->value, self::IN_TRANSIT->value, self::CANCELLED->value],
            self::PARTIALLY_DISPATCHED->value => [self::DISPATCHED->value, self::IN_TRANSIT->value, self::PARTIALLY_RECEIVED->value, self::RECEIVED->value, self::DISCREPANCY_REVIEW->value],
            self::DISPATCHED->value => [self::PARTIALLY_DISPATCHED->value, self::IN_TRANSIT->value, self::PARTIALLY_RECEIVED->value, self::RECEIVED->value, self::DISCREPANCY_REVIEW->value],
            self::IN_TRANSIT->value => [self::PARTIALLY_DISPATCHED->value, self::DISPATCHED->value, self::PARTIALLY_RECEIVED->value, self::RECEIVED->value, self::DISCREPANCY_REVIEW->value],
            self::PARTIALLY_RECEIVED->value => [self::PARTIALLY_DISPATCHED->value, self::DISPATCHED->value, self::IN_TRANSIT->value, self::RECEIVED->value, self::DISCREPANCY_REVIEW->value],
            self::RECEIVED->value => [self::DISCREPANCY_REVIEW->value, self::CLOSED->value],
            self::DISCREPANCY_REVIEW->value => [self::PARTIALLY_DISPATCHED->value, self::DISPATCHED->value, self::IN_TRANSIT->value, self::PARTIALLY_RECEIVED->value, self::RECEIVED->value, self::CLOSED->value],
            self::CLOSED->value => [],
            self::CANCELLED->value => [],
        ];
    }
}
