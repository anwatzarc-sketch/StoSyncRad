<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Enums;

use App\Support\Enums\HasStateTransitions;
use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\StateEnum;

/** Item lifecycle. */
enum ItemStatus: string implements StateEnum
{
    use HasStateTransitions;
    use HasSystemEnum;

    case DRAFT = 'draft';
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
    case ARCHIVED = 'archived';

    public static function transitions(): array
    {
        return [
            self::DRAFT->value => [self::ACTIVE->value, self::ARCHIVED->value],
            self::ACTIVE->value => [self::INACTIVE->value, self::ARCHIVED->value],
            self::INACTIVE->value => [self::ACTIVE->value, self::ARCHIVED->value],
            self::ARCHIVED->value => [],
        ];
    }
}
