<?php

declare(strict_types=1);

namespace App\Domains\CustomerPortal\Enums;

use App\Support\Enums\HasStateTransitions;
use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\StateEnum;

/** Customer self-registration / invitation lifecycle: verify email → set up two-step verification → staff approval (registrations only). */
enum RegistrationStatus: string implements StateEnum
{
    use HasStateTransitions;
    use HasSystemEnum;

    case PENDING_VERIFICATION = 'pending_verification';
    case PENDING_MFA = 'pending_mfa';
    case PENDING_APPROVAL = 'pending_approval';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';

    public static function transitions(): array
    {
        return [
            self::PENDING_VERIFICATION->value => [self::PENDING_MFA->value, self::REJECTED->value],
            self::PENDING_MFA->value => [self::PENDING_APPROVAL->value, self::APPROVED->value, self::REJECTED->value],
            self::PENDING_APPROVAL->value => [self::APPROVED->value, self::REJECTED->value],
            self::APPROVED->value => [],
            self::REJECTED->value => [],
        ];
    }
}
