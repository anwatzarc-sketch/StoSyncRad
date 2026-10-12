<?php

declare(strict_types=1);

namespace App\Domains\CustomerPortal\Enums;

use App\Support\Enums\HasStateTransitions;
use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\StateEnum;

/** Staff invitation of a contact of an existing customer. */
enum InvitationStatus: string implements StateEnum
{
    use HasStateTransitions;
    use HasSystemEnum;

    case PENDING = 'pending';
    case ACCEPTED = 'accepted';
    case REVOKED = 'revoked';
    case EXPIRED = 'expired';

    public static function transitions(): array
    {
        return [
            self::PENDING->value => [self::ACCEPTED->value, self::REVOKED->value, self::EXPIRED->value],
            self::ACCEPTED->value => [],
            self::REVOKED->value => [],
            self::EXPIRED->value => [],
        ];
    }
}
