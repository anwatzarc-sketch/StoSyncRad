<?php

declare(strict_types=1);

namespace App\Domains\Platform\Config;

use App\Domains\Platform\Config\ConfigScopeType as Scope;

/**
 * Terse builders for ConfigKeyDefinition used by the *Keys providers (Appendix C).
 */
trait DefinesConfigKeys
{
    /** @return array<int, Scope> */
    protected static function scopes(string $letters): array
    {
        $map = ['G' => Scope::GLOBAL, 'B' => Scope::BRANCH, 'W' => Scope::WAREHOUSE, 'C' => Scope::CATEGORY, 'I' => Scope::ITEM];

        return array_map(static fn (string $l) => $map[$l], str_split($letters));
    }

    protected static function str(string $key, string $default, string $label, string $help, string $scopes = 'G', array $c = []): ConfigKeyDefinition
    {
        return new ConfigKeyDefinition($key, ConfigValueType::STRING, $default, $label, $help, self::scopes($scopes), null, $c);
    }

    protected static function int(string $key, int $default, string $label, string $help, string $scopes = 'G', ?int $min = null, ?int $max = null, bool $restart = false): ConfigKeyDefinition
    {
        return new ConfigKeyDefinition($key, ConfigValueType::INTEGER, $default, $label, $help, self::scopes($scopes), null,
            array_filter(['min' => $min, 'max' => $max], static fn ($v) => $v !== null), false, $restart);
    }

    protected static function dec(string $key, string $default, string $label, string $help, string $scopes = 'G', ?string $min = null, ?string $max = null): ConfigKeyDefinition
    {
        return new ConfigKeyDefinition($key, ConfigValueType::DECIMAL, $default, $label, $help, self::scopes($scopes), null,
            array_filter(['min' => $min, 'max' => $max], static fn ($v) => $v !== null));
    }

    protected static function pct(string $key, string $default, string $label, string $help, string $scopes = 'G'): ConfigKeyDefinition
    {
        return new ConfigKeyDefinition($key, ConfigValueType::PERCENTAGE, $default, $label, $help, self::scopes($scopes));
    }

    protected static function bool(string $key, bool $default, string $label, string $help, string $scopes = 'G', bool $restart = false): ConfigKeyDefinition
    {
        return new ConfigKeyDefinition($key, ConfigValueType::BOOLEAN, $default, $label, $help, self::scopes($scopes), null, [], false, $restart);
    }

    /** @param  class-string<\App\Support\Enums\SystemEnum>  $enum */
    protected static function enum(string $key, string $enum, string $default, string $label, string $help, string $scopes = 'G'): ConfigKeyDefinition
    {
        return new ConfigKeyDefinition($key, ConfigValueType::ENUM, $default, $label, $help, self::scopes($scopes), $enum);
    }

    /** @param  class-string<\App\Support\Enums\SystemEnum>|null  $itemsEnum */
    protected static function list(string $key, array $default, string $label, string $help, string $scopes = 'G', ?string $itemsEnum = null, string $itemsType = 'string', ?int $minItems = null, ?int $maxItems = null): ConfigKeyDefinition
    {
        $c = array_filter(['items_enum' => $itemsEnum, 'items_type' => $itemsType, 'min_items' => $minItems, 'max_items' => $maxItems], static fn ($v) => $v !== null);

        return new ConfigKeyDefinition($key, ConfigValueType::LIST, $default, $label, $help, self::scopes($scopes), null, $c);
    }

    protected static function json(string $key, array $default, string $label, string $help, string $scopes = 'G'): ConfigKeyDefinition
    {
        return new ConfigKeyDefinition($key, ConfigValueType::JSON, $default, $label, $help, self::scopes($scopes));
    }

    protected static function secret(string $key, string $label, string $help): ConfigKeyDefinition
    {
        return new ConfigKeyDefinition($key, ConfigValueType::SECRET, '', $label, $help, [Scope::GLOBAL], null, [], true);
    }

    protected static function uuid(string $key, ?string $default, string $label, string $help, string $scopes = 'G'): ConfigKeyDefinition
    {
        return new ConfigKeyDefinition($key, ConfigValueType::UUID, $default, $label, $help, self::scopes($scopes));
    }

    protected static function time(string $key, string $default, string $label, string $help): ConfigKeyDefinition
    {
        return new ConfigKeyDefinition($key, ConfigValueType::TIME, $default, $label, $help);
    }
}
