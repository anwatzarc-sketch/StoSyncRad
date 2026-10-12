<?php

declare(strict_types=1);

namespace App\Support\Schema;

use Illuminate\Http\Request;
use LogicException;

/**
 * Accessor for the payload validated by ValidateAgainstSchema.
 */
final class SchemaValidated
{
    /** @return array<string, mixed> */
    public static function from(Request $request): array
    {
        if (! $request->attributes->has(ValidateAgainstSchema::ATTRIBUTE)) {
            throw new LogicException('Route has no schema declaration; call ->schema(SomeSchema::class) on the route.');
        }

        return (array) $request->attributes->get(ValidateAgainstSchema::ATTRIBUTE);
    }

    public static function has(Request $request): bool
    {
        return $request->attributes->has(ValidateAgainstSchema::ATTRIBUTE);
    }
}
