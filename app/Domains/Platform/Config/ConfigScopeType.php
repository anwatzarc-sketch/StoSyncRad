<?php

declare(strict_types=1);

namespace App\Domains\Platform\Config;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/**
 * Scopes at which a configuration key may be overridden. Resolution precedence (Chapter 03 §3.1):
 * item → category → warehouse → branch → global → seed default → code default.
 */
enum ConfigScopeType: string implements SystemEnum
{
    use HasSystemEnum;

    case GLOBAL = 'global';
    case BRANCH = 'branch';
    case WAREHOUSE = 'warehouse';
    case CATEGORY = 'category';
    case ITEM = 'item';

    /** Most specific first. */
    public static function precedence(): array
    {
        return [self::ITEM, self::CATEGORY, self::WAREHOUSE, self::BRANCH, self::GLOBAL];
    }
}
