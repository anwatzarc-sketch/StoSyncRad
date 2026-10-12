<?php

declare(strict_types=1);

namespace App\Support\Schema;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/**
 * Data types a table property may declare (Chapter 05 §5.3).
 */
enum PropertyType: string implements SystemEnum
{
    use HasSystemEnum;

    case UUID = 'uuid';
    case STRING = 'string';
    case TEXT = 'text';
    case INTEGER = 'integer';
    case DECIMAL = 'decimal';
    case BOOLEAN = 'boolean';
    case DATE = 'date';
    case DATETIME = 'datetime';
    case TIME = 'time';
    case ENUM = 'enum';
    case JSON = 'json';
    case MONEY = 'money';
    case QUANTITY = 'quantity';
    case PERCENTAGE = 'percentage';
    case EMAIL = 'email';
    case PHONE = 'phone';
    case URL = 'url';
    case BARCODE = 'barcode';
    case FILE_REF = 'file_ref';
    case ARRAY = 'array';
    case ANY = 'any';   // value checked downstream by its own registry (configuration values)

    /** TypeScript type used by the generated client types. */
    public function tsType(): string
    {
        return match ($this) {
            self::INTEGER => 'number',
            self::BOOLEAN => 'boolean',
            self::JSON => 'Record<string, unknown>',
            self::ARRAY => 'unknown[]',
            default => 'string',
        };
    }

    public function isNumeric(): bool
    {
        return in_array($this, [self::INTEGER, self::DECIMAL, self::MONEY, self::QUANTITY, self::PERCENTAGE], true);
    }

    public function isDecimal(): bool
    {
        return in_array($this, [self::DECIMAL, self::MONEY, self::QUANTITY, self::PERCENTAGE], true);
    }
}
