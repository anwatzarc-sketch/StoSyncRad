<?php

declare(strict_types=1);

namespace App\Domains\Platform\Config;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

enum ConfigValueType: string implements SystemEnum
{
    use HasSystemEnum;

    case STRING = 'string';
    case INTEGER = 'integer';
    case DECIMAL = 'decimal';
    case BOOLEAN = 'boolean';
    case ENUM = 'enum';
    case JSON = 'json';
    case DURATION = 'duration';
    case PERCENTAGE = 'percentage';
    case MONEY = 'money';
    case LIST = 'list';
    case SECRET = 'secret';
    case ASSET_REF = 'asset_ref';
    case UUID = 'uuid';
    case TIME = 'time';
}
