<?php

declare(strict_types=1);

namespace App\Support\Enums;

/**
 * Contract implemented by every string-backed system enum (Chapter 05 §5.2).
 *
 * Semantics are fixed in code; labels are configurable per locale through enum_labels.
 */
interface SystemEnum
{
    /** @return array<int, string> */
    public static function values(): array;

    public static function tryFromInput(mixed $value): ?static;

    public static function enumCode(): string;

    public function label(): string;

    public function description(): ?string;
}
