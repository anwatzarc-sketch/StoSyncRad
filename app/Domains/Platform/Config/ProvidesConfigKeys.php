<?php

declare(strict_types=1);

namespace App\Domains\Platform\Config;

interface ProvidesConfigKeys
{
    /** @return array<int, ConfigKeyDefinition> */
    public static function definitions(): array;
}
