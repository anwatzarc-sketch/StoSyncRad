<?php

declare(strict_types=1);

namespace App\Support\Eloquent\Casts;

use BackedEnum;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Casts a PostgreSQL text/varchar array ("{a,b}") to a PHP list of strings.
 * Elements are validated against an optional SystemEnum class on write.
 *
 * Usage: 'capabilities' => PgTextArray::class.':'.WarehouseCapability::class
 *
 * @implements CastsAttributes<array<int,string>, array<int,string|BackedEnum>>
 */
final class PgTextArray implements CastsAttributes
{
    /** @param  class-string<BackedEnum>|null  $enumClass */
    public function __construct(private readonly ?string $enumClass = null) {}

    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [];
        }
        if (is_array($value)) {
            return array_values($value);
        }

        return self::parse((string) $value);
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

        return self::format(array_values(array_unique($items)));
    }

    /** @return array<int, string> */
    public static function parse(string $literal): array
    {
        $literal = trim($literal);
        if ($literal === '{}' || $literal === '') {
            return [];
        }
        $inner = substr($literal, 1, -1);
        $out = [];
        $current = '';
        $quoted = false;
        $len = strlen($inner);
        for ($i = 0; $i < $len; $i++) {
            $ch = $inner[$i];
            if ($quoted) {
                if ($ch === '\\' && $i + 1 < $len) {
                    $current .= $inner[++$i];
                } elseif ($ch === '"') {
                    $quoted = false;
                } else {
                    $current .= $ch;
                }
            } elseif ($ch === '"') {
                $quoted = true;
            } elseif ($ch === ',') {
                $out[] = $current;
                $current = '';
            } else {
                $current .= $ch;
            }
        }
        $out[] = $current;

        return $out;
    }

    /** @param  array<int, string>  $items */
    public static function format(array $items): string
    {
        return '{'.implode(',', array_map(static fn (string $s) => '"'.addcslashes($s, '"\\').'"', $items)).'}';
    }
}
