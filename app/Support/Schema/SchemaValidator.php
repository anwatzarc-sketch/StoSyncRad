<?php

declare(strict_types=1);

namespace App\Support\Schema;

use App\Support\Validation\ValidationErrorCode as Code;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Validates a write payload against a TableSchema (Chapter 05 §5.4 pipeline steps 2–6).
 * Returns the coerced payload (enums as raw values, decimals as strings, booleans as bool).
 *
 * Business rules (stock availability, SoD, approvals) are NOT handled here; they belong to
 * FormRequests/Actions, which receive this validated DTO.
 */
final class SchemaValidator
{
    /** @var array<int, array{property: string, message: string}> */
    private array $errors = [];

    /**
     * @param  class-string<TableSchema>  $schemaClass
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validate(string $schemaClass, array $payload, ValidationContext $ctx): array
    {
        $this->errors = [];
        $validated = $this->validateObject($schemaClass, $payload, $ctx, '');

        if ($this->errors !== []) {
            $messages = [];
            foreach ($this->errors as $error) {
                $messages[$error['property']][] = $error['message'];
            }
            throw ValidationException::withMessages($messages);
        }

        return $validated;
    }

    /**
     * @param  class-string<TableSchema>  $schemaClass
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateObject(string $schemaClass, array $payload, ValidationContext $ctx, string $prefix): array
    {
        $properties = $schemaClass::properties();
        $out = [];

        foreach ($payload as $key => $value) {
            if (! isset($properties[$key])) {
                if (! $ctx->allowUnknown) {
                    $this->fail($prefix.$key, Code::UNKNOWN_PROPERTY);
                }

                continue;
            }
        }

        foreach ($properties as $name => $property) {
            $path = $prefix.$name;
            $present = array_key_exists($name, $payload);
            $value = $present ? $payload[$name] : null;

            if ($present && $property->serverControlled) {
                $this->fail($path, Code::SERVER_CONTROLLED_PROPERTY);

                continue;
            }

            if (! $present) {
                if ($property->isRequiredFor($ctx->operation) && ! $property->hasDefault && $ctx->operation !== 'update') {
                    $this->fail($path, Code::REQUIRED);
                } elseif ($ctx->operation === 'update' && $property->requiredOn === 'update') {
                    $this->fail($path, Code::REQUIRED);
                }

                continue;
            }

            if ($property->writableBy !== null && ! $ctx->can($property->writableBy)) {
                $this->fail($path, Code::PROPERTY_FORBIDDEN, ['permission' => $property->writableBy]);

                continue;
            }

            if ($ctx->operation === 'update' && $ctx->currentState !== null
                && in_array($ctx->currentState, $property->immutableAfter, true)) {
                $this->fail($path, Code::PROPERTY_IMMUTABLE, ['state' => $ctx->currentState]);

                continue;
            }

            if ($value === null) {
                if (! $property->isNullable()) {
                    $this->fail($path, $property->isRequiredFor($ctx->operation) ? Code::REQUIRED : Code::NULL_NOT_ALLOWED);

                    continue;
                }
                $out[$name] = null;

                continue;
            }

            $coerced = $this->coerce($path, $property, $value, $ctx);
            if ($coerced === self::INVALID) {
                continue;
            }
            $out[$name] = $coerced;
        }

        $this->checkDateOrdering($properties, $out, $prefix);

        return $out;
    }

    private const INVALID = "\0invalid\0";

    private function coerce(string $path, Property $p, mixed $value, ValidationContext $ctx): mixed
    {
        return match ($p->type) {
            PropertyType::UUID => $this->coerceUuid($path, $p, $value),
            PropertyType::STRING, PropertyType::TEXT, PropertyType::EMAIL, PropertyType::PHONE,
            PropertyType::URL, PropertyType::BARCODE, PropertyType::FILE_REF => $this->coerceString($path, $p, $value),
            PropertyType::INTEGER => $this->coerceInteger($path, $p, $value),
            PropertyType::DECIMAL, PropertyType::MONEY, PropertyType::QUANTITY, PropertyType::PERCENTAGE => $this->coerceDecimal($path, $p, $value),
            PropertyType::BOOLEAN => $this->coerceBoolean($path, $value),
            PropertyType::DATE => $this->coerceDate($path, $value, 'Y-m-d'),
            PropertyType::DATETIME => $this->coerceDateTime($path, $value),
            PropertyType::TIME => $this->coerceTime($path, $value),
            PropertyType::ENUM => $this->coerceEnum($path, $p, $value),
            PropertyType::JSON => $this->coerceJson($path, $value),
            PropertyType::ARRAY => $this->coerceArray($path, $p, $value, $ctx),
            PropertyType::ANY => $value,
        };
    }

    private function coerceUuid(string $path, Property $p, mixed $value): mixed
    {
        if (! is_string($value) || ! Str::isUuid($value)) {
            $this->fail($path, Code::INVALID_IDENTIFIER, ['received' => $this->describe($value)]);

            return self::INVALID;
        }
        $value = strtolower($value);

        if ($p->references !== null && ! $this->referenceExists($path, $p, $value)) {
            return self::INVALID;
        }

        return $value;
    }

    private function coerceString(string $path, Property $p, mixed $value): mixed
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            $this->fail($path, Code::TYPE_MISMATCH, ['expected' => 'string', 'received' => $this->describe($value)]);

            return self::INVALID;
        }
        $value = (string) $value;
        if ($p->trim) {
            $value = trim($value);
        }
        if ($p->maxLength !== null && mb_strlen($value) > $p->maxLength) {
            $this->fail($path, Code::LENGTH_EXCEEDED, ['max' => $p->maxLength, 'length' => mb_strlen($value)]);

            return self::INVALID;
        }
        $formatOk = match ($p->type) {
            PropertyType::EMAIL => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            PropertyType::URL => filter_var($value, FILTER_VALIDATE_URL) !== false,
            PropertyType::PHONE => preg_match('/^\+?[0-9 ()\-.]{5,32}$/', $value) === 1,
            PropertyType::BARCODE => preg_match('/^[A-Za-z0-9\-_.\/+ ]{1,128}$/', $value) === 1,
            default => true,
        };
        if (! $formatOk) {
            $this->fail($path, Code::FORMAT_INVALID, ['expected' => $p->type->value]);

            return self::INVALID;
        }
        if ($p->pattern !== null && preg_match($p->pattern, $value) !== 1) {
            $this->fail($path, Code::FORMAT_INVALID, ['expected' => $p->pattern]);

            return self::INVALID;
        }

        return $value;
    }

    private function coerceInteger(string $path, Property $p, mixed $value): mixed
    {
        if (is_bool($value) || ! (is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1))) {
            $this->fail($path, Code::TYPE_MISMATCH, ['expected' => 'integer', 'received' => $this->describe($value)]);

            return self::INVALID;
        }
        $int = (int) $value;
        if (($p->min !== null && $int < (int) $p->min) || ($p->max !== null && $int > (int) $p->max)) {
            $this->fail($path, Code::RANGE_INVALID, array_filter(['min' => $p->min, 'max' => $p->max, 'received' => $int], static fn ($v) => $v !== null));

            return self::INVALID;
        }

        return $int;
    }

    private function coerceDecimal(string $path, Property $p, mixed $value): mixed
    {
        if (is_bool($value) || ! (is_int($value) || is_float($value) || (is_string($value) && is_numeric(trim($value))))) {
            $this->fail($path, Code::TYPE_MISMATCH, ['expected' => 'decimal', 'received' => $this->describe($value)]);

            return self::INVALID;
        }
        $str = is_string($value) ? trim($value) : (is_float($value) ? rtrim(rtrim(number_format($value, 10, '.', ''), '0'), '.') : (string) $value);
        if (preg_match('/^-?\d+(\.\d+)?$/', $str) !== 1) {
            $this->fail($path, Code::FORMAT_INVALID, ['expected' => 'decimal']);

            return self::INVALID;
        }
        $scale = $p->scale ?? 6;
        $decimals = str_contains($str, '.') ? strlen(explode('.', $str)[1]) : 0;
        if ($decimals > $scale) {
            $this->fail($path, Code::PRECISION_EXCEEDED, ['scale' => $scale, 'received' => $str]);

            return self::INVALID;
        }
        $integerDigits = strlen(ltrim(explode('.', $str)[0], '-'));
        if ($p->precision !== null && $integerDigits > $p->precision - $scale) {
            $this->fail($path, Code::RANGE_INVALID, ['max_integer_digits' => $p->precision - $scale]);

            return self::INVALID;
        }
        if (($p->min !== null && bccomp($str, $p->min, 10) < 0) || ($p->max !== null && bccomp($str, $p->max, 10) > 0)) {
            $this->fail($path, Code::RANGE_INVALID, array_filter(['min' => $p->min, 'max' => $p->max, 'received' => $str], static fn ($v) => $v !== null));

            return self::INVALID;
        }

        return $str;
    }

    private function coerceBoolean(string $path, mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value;
        }
        if (in_array($value, [1, 0, '1', '0', 'true', 'false'], true)) {
            return in_array($value, [1, '1', 'true'], true);
        }
        $this->fail($path, Code::TYPE_MISMATCH, ['expected' => 'boolean', 'received' => $this->describe($value)]);

        return self::INVALID;
    }

    private function coerceDate(string $path, mixed $value, string $format): mixed
    {
        if (is_string($value)) {
            $dt = CarbonImmutable::createFromFormat('!'.$format, $value);
            if ($dt !== false && $dt->format($format) === $value) {
                return $value;
            }
        }
        $this->fail($path, Code::FORMAT_INVALID, ['expected' => $format]);

        return self::INVALID;
    }

    private function coerceDateTime(string $path, mixed $value): mixed
    {
        if (is_string($value)) {
            try {
                return CarbonImmutable::parse($value)->utc()->toIso8601String();
            } catch (Throwable) {
            }
        }
        $this->fail($path, Code::FORMAT_INVALID, ['expected' => 'ISO-8601 datetime']);

        return self::INVALID;
    }

    private function coerceTime(string $path, mixed $value): mixed
    {
        if (is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $value) === 1) {
            return strlen($value) === 5 ? $value.':00' : $value;
        }
        $this->fail($path, Code::FORMAT_INVALID, ['expected' => 'HH:MM[:SS]']);

        return self::INVALID;
    }

    private function coerceEnum(string $path, Property $p, mixed $value): mixed
    {
        $enum = $p->enumClass;
        $case = $enum::tryFromInput($value);
        if ($case === null) {
            $this->fail($path, Code::ENUM_INVALID, ['allowed_values' => $enum::values(), 'received' => $this->describe($value)]);

            return self::INVALID;
        }

        return $case->value;
    }

    private function coerceJson(string $path, mixed $value): mixed
    {
        if (is_array($value)) {
            return $value;
        }
        $this->fail($path, Code::TYPE_MISMATCH, ['expected' => 'object', 'received' => $this->describe($value)]);

        return self::INVALID;
    }

    private function coerceArray(string $path, Property $p, mixed $value, ValidationContext $ctx): mixed
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $this->fail($path, Code::TYPE_MISMATCH, ['expected' => 'array', 'received' => $this->describe($value)]);

            return self::INVALID;
        }
        if ($p->max !== null && count($value) > (int) $p->max) {
            $this->fail($path, Code::RANGE_INVALID, ['max_items' => (int) $p->max, 'received' => count($value)]);

            return self::INVALID;
        }
        $out = [];
        foreach ($value as $i => $element) {
            if ($p->items !== null) {
                if (! is_array($element)) {
                    $this->fail("$path.$i", Code::TYPE_MISMATCH, ['expected' => 'object']);

                    continue;
                }
                $out[] = $this->validateObject($p->items, $element, $ctx, "$path.$i.");
            } elseif ($p->itemProperty !== null) {
                $coerced = $this->coerce("$path.$i", $p->itemProperty, $element, $ctx);
                if ($coerced !== self::INVALID) {
                    $out[] = $coerced;
                }
            } else {
                $out[] = $element;
            }
        }

        return $out;
    }

    private function referenceExists(string $path, Property $p, string $id): bool
    {
        /** @var class-string<Model> $model */
        $model = $p->references;
        $query = $model::query()->whereKey($id);
        $row = $query->first($p->referenceStatusColumn ? ['id', $p->referenceStatusColumn] : ['id']);

        if ($row === null) {
            $this->fail($path, Code::REFERENCE_NOT_FOUND, ['reference' => class_basename($model)]);

            return false;
        }
        if ($p->referenceStatusColumn !== null && $p->referenceActiveValues !== []) {
            $status = $row->{$p->referenceStatusColumn};
            $status = $status instanceof \BackedEnum ? $status->value : $status;
            if (! in_array($status, $p->referenceActiveValues, true)) {
                $this->fail($path, Code::REFERENCE_INACTIVE, ['reference' => class_basename($model), 'status' => $status]);

                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, Property>  $properties
     * @param  array<string, mixed>  $out
     */
    private function checkDateOrdering(array $properties, array $out, string $prefix): void
    {
        foreach ($properties as $name => $p) {
            if (! isset($out[$name]) || ! in_array($p->type, [PropertyType::DATE, PropertyType::DATETIME], true)) {
                continue;
            }
            foreach (['after' => '>=', 'before' => '<='] as $rel => $op) {
                $other = $p->{$rel};
                if ($other === null || ! isset($out[$other])) {
                    continue;
                }
                $ok = $op === '>=' ? $out[$name] >= $out[$other] : $out[$name] <= $out[$other];
                if (! $ok) {
                    $this->fail($prefix.$name, Code::DATE_ORDER_INVALID, ['relation' => $rel === 'after' ? 'on or after' : 'on or before', 'other' => $other]);
                }
            }
        }
    }

    /** @param  array<string, mixed>  $payload */
    private function fail(string $property, Code $code, array $payload = []): void
    {
        $this->errors[] = ['property' => $property, 'message' => $code->message($property, $payload)];
    }

    private function describe(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : get_debug_type($value);
    }
}
