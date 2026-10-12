<?php

declare(strict_types=1);

namespace App\Domains\Platform\Config;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Runtime configuration resolver (Chapter 03 §3.1–3.3).
 *
 * Resolution: item → category → warehouse → branch → global entry → declared default.
 * Reads are memoized per request and cached across requests; writes go through
 * SetConfigurationValueAction which invalidates the cache.
 *
 * Safe before migrations/setup: when the table is absent, declared defaults are returned.
 */
final class PlatformConfig
{
    public const CACHE_KEY = 'platform.configuration_entries';

    /** @var array<string, array<string, mixed>>|null  "scope_type:scope_id" → key → value */
    private ?array $entries = null;

    public function __construct(private readonly ConfigRegistry $registry, private readonly Cache $cache) {}

    /**
     * Effective value for a key, optionally within a scope chain.
     *
     * @param  array<string, string|null>  $scope  e.g. ['warehouse' => $id, 'branch' => $id, 'category' => $id, 'item' => $id]
     */
    public function get(string $key, array $scope = []): mixed
    {
        $definition = $this->registry->get($key);
        $entries = $this->entries();

        foreach (ConfigScopeType::precedence() as $scopeType) {
            if ($scopeType === ConfigScopeType::GLOBAL) {
                $bucket = $entries['global:'] ?? [];
            } else {
                $id = $scope[$scopeType->value] ?? null;
                if ($id === null || ! $definition->allowsScope($scopeType)) {
                    continue;
                }
                $bucket = $entries[$scopeType->value.':'.$id] ?? [];
            }
            if (array_key_exists($key, $bucket)) {
                return $this->decode($definition, $bucket[$key]);
            }
        }

        return $definition->default;
    }

    public function string(string $key, array $scope = []): string
    {
        return (string) $this->get($key, $scope);
    }

    public function int(string $key, array $scope = []): int
    {
        return (int) $this->get($key, $scope);
    }

    public function bool(string $key, array $scope = []): bool
    {
        return (bool) $this->get($key, $scope);
    }

    /** @return array<int, mixed> */
    public function list(string $key, array $scope = []): array
    {
        return (array) $this->get($key, $scope);
    }

    public function feature(string $flag): bool
    {
        $key = Str::startsWith($flag, 'feature.') ? $flag : 'feature.'.$flag;

        return $this->registry->has($key) && (bool) $this->get($key);
    }

    /** @return array<string, bool> */
    public function features(): array
    {
        return $this->registry->group('feature')
            ->mapWithKeys(fn (ConfigKeyDefinition $d) => [Str::after($d->key, 'feature.') => (bool) $this->get($d->key)])
            ->all();
    }

    /**
     * Where a value comes from, for the admin UI ("inherited from …").
     *
     * @return array{value: mixed, source: string, scope_id: ?string}
     */
    public function explain(string $key, array $scope = []): array
    {
        $definition = $this->registry->get($key);
        $entries = $this->entries();
        foreach (ConfigScopeType::precedence() as $scopeType) {
            $id = $scopeType === ConfigScopeType::GLOBAL ? '' : ($scope[$scopeType->value] ?? null);
            if ($id === null || ($scopeType !== ConfigScopeType::GLOBAL && ! $definition->allowsScope($scopeType))) {
                continue;
            }
            $bucket = $entries[$scopeType->value.':'.$id] ?? [];
            if (array_key_exists($key, $bucket)) {
                return ['value' => $this->decode($definition, $bucket[$key]), 'source' => $scopeType->value, 'scope_id' => $id ?: null];
            }
        }

        return ['value' => $definition->default, 'source' => 'default', 'scope_id' => null];
    }

    public function forget(): void
    {
        $this->entries = null;
        $this->cache->forget(self::CACHE_KEY);
    }

    /** @return array<string, array<string, mixed>> */
    private function entries(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }
        // Inside a transaction the rows may include an uncommitted write: keep them for this request
        // only. Publishing them to the shared cache would leave a phantom value after a rollback, and
        // the next identical submission would then be skipped as "unchanged".
        if (DB::transactionLevel() > 0) {
            return $this->entries = $this->load();
        }

        return $this->entries = $this->cache->rememberForever(self::CACHE_KEY, fn (): array => $this->load());
    }

    /** @return array<string, array<string, mixed>> */
    private function load(): array
    {
        try {
            if (! Schema::hasTable('configuration_entries')) {
                return [];
            }
            $rows = DB::table('configuration_entries')->get(['key', 'scope_type', 'scope_id', 'value']);
        } catch (QueryException) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $out[$row->scope_type.':'.($row->scope_id ?? '')][$row->key] = $row->value;
        }

        return $out;
    }

    private function decode(ConfigKeyDefinition $definition, mixed $stored): mixed
    {
        $value = is_string($stored) ? json_decode($stored, true, 512, JSON_THROW_ON_ERROR) : $stored;
        if ($definition->sensitive && is_string($value) && $value !== '') {
            try {
                return decrypt($value);
            } catch (\Throwable) {
                return null;
            }
        }

        return $value;
    }
}
