<?php

declare(strict_types=1);

namespace App\Domains\Platform\Config;

use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Illuminate\Support\Collection;
use LogicException;

/**
 * Collects every ConfigKeyDefinition from the providers listed in config/platform.php.
 * Undeclared keys cannot be read or written (Chapter 03 §3.2).
 */
final class ConfigRegistry
{
    /** @var array<string, ConfigKeyDefinition>|null */
    private ?array $definitions = null;

    /** @return Collection<string, ConfigKeyDefinition> */
    public function all(): Collection
    {
        if ($this->definitions === null) {
            $this->definitions = $this->collect();
        }

        return collect($this->definitions);
    }

    public function has(string $key): bool
    {
        return $this->all()->has($key);
    }

    public function get(string $key): ConfigKeyDefinition
    {
        $definition = $this->all()->get($key);
        if ($definition === null) {
            throw DomainException::of(ErrorCode::CONFIG_KEY_UNKNOWN, "Configuration key `{$key}` is not declared.", ['key' => $key]);
        }

        return $definition;
    }

    /** @return Collection<string, ConfigKeyDefinition> */
    public function group(string $group): Collection
    {
        return $this->all()->filter(static fn (ConfigKeyDefinition $d) => $d->group() === $group);
    }

    /** @return array<int, string> */
    public function groups(): array
    {
        return $this->all()->map(static fn (ConfigKeyDefinition $d) => $d->group())->unique()->values()->all();
    }

    /** @return array<string, mixed> key → default */
    public function defaults(): array
    {
        return $this->all()->map(static fn (ConfigKeyDefinition $d) => $d->default)->all();
    }

    /** @return array<string, ConfigKeyDefinition> */
    private function collect(): array
    {
        $out = [];
        foreach ((array) config('platform.config_key_providers') as $provider) {
            if (! is_subclass_of($provider, ProvidesConfigKeys::class)) {
                throw new LogicException("$provider must implement ProvidesConfigKeys");
            }
            foreach ($provider::definitions() as $definition) {
                if (isset($out[$definition->key])) {
                    throw new LogicException("Configuration key {$definition->key} declared twice");
                }
                if ($definition->help === '' || $definition->label === '') {
                    throw new LogicException("Configuration key {$definition->key} needs a label and help text");
                }
                $out[$definition->key] = $definition;
            }
        }
        ksort($out);

        return $out;
    }
}
