<?php

declare(strict_types=1);

namespace App\Domains\ReportingEngine\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Report field data type. */
enum ReportFieldType: string implements SystemEnum
{
    use HasSystemEnum;

    case UUID = 'uuid';
    case STRING = 'string';
    case ENUM = 'enum';
    case INTEGER = 'integer';
    case DECIMAL = 'decimal';
    case MONEY = 'money';
    case QUANTITY = 'quantity';
    case DATE = 'date';
    case DATETIME = 'datetime';
    case BOOLEAN = 'boolean';
    case JSON = 'json';

    public function isNumeric(): bool
    {
        return in_array($this, [self::INTEGER, self::DECIMAL, self::MONEY, self::QUANTITY], true);
    }

    public function isTemporal(): bool
    {
        return $this === self::DATE || $this === self::DATETIME;
    }
}
