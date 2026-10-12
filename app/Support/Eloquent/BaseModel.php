<?php

declare(strict_types=1);

namespace App\Support\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Base for every application model.
 *
 * - UUID v7 primary keys generated application-side (Chapter 05 §5.1).
 * - No mass-assignment guessing: $fillable is derived from the table schema when present.
 * - Timestamps are timestamptz in UTC.
 */
abstract class BaseModel extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var class-string<\App\Support\Schema\TableSchema>|null */
    protected static ?string $schema = null;

    /**
     * HasUuids in Laravel 12+ emits ordered (v7) UUIDs; keep it explicit so a framework
     * default change can never silently downgrade key ordering.
     */
    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /**
     * Properties writable through ordinary create/update, derived from the schema when a
     * schema is declared (server-controlled and immutable properties are excluded).
     *
     * @return array<int, string>
     */
    public function getFillable(): array
    {
        if ($this->fillable !== [] || static::$schema === null) {
            return $this->fillable;
        }

        return static::$schema::fillable();
    }

    /** @return class-string<\App\Support\Schema\TableSchema>|null */
    public static function schema(): ?string
    {
        return static::$schema;
    }
}
