<?php

declare(strict_types=1);

namespace App\Support\Enums;

use App\Support\Validation\ValidationErrorCode;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validation rule for SystemEnum-typed properties. Produces the explicit message required by
 * Chapter 05 §5.2 and carries the machine-readable payload for the error envelope.
 */
final class EnumRule implements ValidationRule
{
    /** @param class-string<SystemEnum> $enumClass */
    public function __construct(private readonly string $enumClass) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value instanceof $this->enumClass) {
            return;
        }

        if ((is_string($value) || is_int($value)) && $this->enumClass::tryFromInput($value) !== null) {
            return;
        }

        $allowed = $this->enumClass::values();
        $received = is_scalar($value) ? (string) $value : get_debug_type($value);

        $fail(ValidationErrorCode::ENUM_INVALID->message($attribute, [
            'allowed_values' => $allowed,
            'received' => $received,
        ]));
    }

    /** @return class-string<SystemEnum> */
    public function enumClass(): string
    {
        return $this->enumClass;
    }
}
