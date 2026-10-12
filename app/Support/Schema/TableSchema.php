<?php

declare(strict_types=1);

namespace App\Support\Schema;

use Illuminate\Support\Str;

/**
 * Declarative schema for one table/model (Chapter 05 §5.3).
 *
 * Subclasses implement properties(). Everything else (fillable list, API docs, TS types,
 * validation rules, reporting metadata) derives from that single declaration.
 */
abstract class TableSchema
{
    /** @var array<class-string<static>, array<string, Property>> */
    private static array $memo = [];

    /** Database table name. */
    abstract public static function table(): string;

    /** @return array<string, Property> property name → definition */
    abstract protected static function define(): array;

    /** Stable schema name used in route metadata, OpenAPI and TS ("item", "goods_receipt"). */
    public static function name(): string
    {
        return Str::snake(Str::beforeLast(class_basename(static::class), 'Schema'));
    }

    /** State enum class driving immutableAfter() checks, if any. */
    public static function stateEnum(): ?string
    {
        return null;
    }

    /** Column holding the document state (defaults to "status"). */
    public static function stateColumn(): string
    {
        return 'status';
    }

    /** @return array<string, Property> */
    public static function properties(): array
    {
        return self::$memo[static::class] ??= static::define();
    }

    public static function property(string $name): ?Property
    {
        return static::properties()[$name] ?? null;
    }

    public static function has(string $name): bool
    {
        return isset(static::properties()[$name]);
    }

    /**
     * Properties accepted from clients on ordinary writes (never server-controlled).
     *
     * @return array<int, string>
     */
    public static function fillable(): array
    {
        return array_keys(array_filter(static::properties(), static fn (Property $p) => ! $p->serverControlled && ! $p->virtual));
    }

    /**
     * Keep only mass-assignable column values from a validated payload.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function columns(array $validated): array
    {
        return array_intersect_key($validated, array_flip(static::fillable()));
    }

    /** @return array<int, string> */
    public static function searchable(): array
    {
        return array_keys(array_filter(static::properties(), static fn (Property $p) => $p->searchable));
    }

    /** @return array<int, string> */
    public static function sortable(): array
    {
        return array_keys(array_filter(static::properties(), static fn (Property $p) => $p->sortable));
    }

    /** @return array<int, string> */
    public static function filterable(): array
    {
        return array_keys(array_filter(static::properties(), static fn (Property $p) => $p->filterable));
    }

    /** @return array<int, string> */
    public static function sensitive(): array
    {
        return array_keys(array_filter(static::properties(), static fn (Property $p) => $p->sensitive));
    }

    /** @return array<string, mixed> */
    public static function toArray(): array
    {
        return [
            'name' => static::name(),
            'table' => static::table(),
            'state_enum' => static::stateEnum() ? static::stateEnum()::enumCode() : null,
            'properties' => array_map(static fn (Property $p) => $p->toArray(), static::properties()),
        ];
    }
}
