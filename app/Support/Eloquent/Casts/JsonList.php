<?php

declare(strict_types=1);

namespace App\Support\Eloquent\Casts;

use BackedEnum;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * A JSON array column holding a list of strings (warehouse capabilities, partner types, notification
 * channels, webhook event types, report tags, shared role ids). Values are de-duplicated and, when an
 * enum class is given, validated against its cases — the database CHECK enforces the same.
 *
 * Usage: 'capabilities' => JsonList::class.':'.WarehouseCapability::class
 *
 * @implements CastsAttributes<array<int, string>, array<int, string|BackedEnum>>
 */
final class JsonList implements CastsAttributes
{
    /** @param  class-string<BackedEnum>|null  $enumClass */
    public function __construct(private readonly ?string $enumClass = null) {}

    /** @return array<int, string> */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        $decoded = is_array($value) ? $value : json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);

        return array_values(array_map('strval', (array) $decoded));
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_array($value)) {
            throw new InvalidArgumentException("$key must be an array");
        }
        $items = [];
        foreach ($value as $item) {
            $item = $item instanceof BackedEnum ? (string) $item->value : (string) $item;
            if ($this->enumClass !== null && $this->enumClass::tryFrom($item) === null) {
                throw new InvalidArgumentException("$key contains invalid value `$item`");
            }
            $items[] = $item;
        }

        return json_encode(array_values(array_unique($items)), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
