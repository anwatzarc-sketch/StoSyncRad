<?php

declare(strict_types=1);

namespace App\Support\Enums;

/**
 * Data classification applied to properties, datasets and exports (Chapter 10 §10.2, Chapter 14).
 */
enum DataClassification: string implements SystemEnum
{
    use HasSystemEnum;

    case PUBLIC_INTERNAL = 'public_internal';
    case OPERATIONAL = 'operational';
    case COST_SENSITIVE = 'cost_sensitive';
    case PERSONAL_DATA = 'personal_data';
    case RESTRICTED = 'restricted';

    /** Virtual permission required to read fields of this classification (null = none). */
    public function requiredPermission(): ?string
    {
        return match ($this) {
            self::COST_SENSITIVE => 'reporting.cost_fields.view',
            self::PERSONAL_DATA => 'reporting.personal_fields.view',
            self::RESTRICTED => 'reporting.restricted_fields.view',
            default => null,
        };
    }
}
