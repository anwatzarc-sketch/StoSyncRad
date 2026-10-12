<?php

declare(strict_types=1);

namespace App\Domains\Platform\Schemas;

use App\Domains\Platform\Config\ConfigScopeType;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Set a configuration value (typed and range-checked by the configuration registry). */
final class ConfigValueSchema extends TableSchema
{
    public static function table(): string
    {
        return 'configuration_entries';
    }

    protected static function define(): array
    {
        return [
            'value' => Property::any()->virtual()->label('Value'),
            'scope_type' => Property::enum(ConfigScopeType::class)->default(ConfigScopeType::GLOBAL->value)->virtual()->label('Scope'),
            'scope_id' => Property::uuid()->nullable()->virtual()->label('Scope id'),
            'version' => Property::integer()->nullable()->min(1)->virtual()->label('Expected version'),
            'reason' => Property::text(2000)->nullable()->trim()->virtual()->label('Reason'),
        ];
    }
}
