<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Sentinel UUID for absent position dimensions (no variant, no lot, no owner, no package) so
 * that unique constraints over stock positions stay total (Chapter 07 §7.3).
 */
final class Sentinel
{
    public const UUID = '00000000-0000-7000-8000-000000000000';

    public static function orId(?string $id): string
    {
        return $id ?? self::UUID;
    }

    public static function toNull(?string $id): ?string
    {
        return $id === null || $id === self::UUID ? null : $id;
    }

    public static function is(?string $id): bool
    {
        return $id === self::UUID;
    }
}
