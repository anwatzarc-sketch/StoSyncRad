<?php

declare(strict_types=1);

namespace App\Support\Enums;

use BackedEnum;
use Illuminate\Support\Str;

/**
 * Default SystemEnum behaviour for string-backed enums.
 *
 * Labels resolve through the EnumLabelResolver (client-editable, localized) and fall back
 * to a humanized case name so raw codes never reach the UI.
 */
trait HasSystemEnum
{
    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (BackedEnum $case) => (string) $case->value, static::cases());
    }

    public static function tryFromInput(mixed $value): ?static
    {
        if ($value instanceof static) {
            return $value;
        }

        if (is_string($value) || is_int($value)) {
            return static::tryFrom((string) $value);
        }

        return null;
    }

    /** Stable code used in enum_labels and the meta API (e.g. "document_status"). */
    public static function enumCode(): string
    {
        return Str::snake(class_basename(static::class));
    }

    public function label(): string
    {
        return app(EnumLabelResolver::class)->label($this);
    }

    public function description(): ?string
    {
        return app(EnumLabelResolver::class)->description($this);
    }

    /** Humanized fallback label ("partially_received" → "Partially received"). */
    public function defaultLabel(): string
    {
        return Str::ucfirst(str_replace('_', ' ', (string) $this->value));
    }

    public function is(self|string|null $other): bool
    {
        if ($other === null) {
            return false;
        }

        return $this->value === ($other instanceof self ? $other->value : $other);
    }

    /** @param  array<int, self|string>  $others */
    public function in(array $others): bool
    {
        foreach ($others as $other) {
            if ($this->is($other)) {
                return true;
            }
        }

        return false;
    }
}
