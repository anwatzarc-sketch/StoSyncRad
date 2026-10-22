<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

/**
 * Case-insensitive text matching. Columns use the case-sensitive utf8mb4_0900_as_cs collation (so
 * codes, SKUs and lot numbers stay distinct), and searches compare under the case-insensitive but
 * accent-sensitive utf8mb4_0900_as_ci collation — the semantics of PostgreSQL's ILIKE.
 */
final class Search
{
    public const CASE_INSENSITIVE_COLLATION = 'utf8mb4_0900_as_ci';

    /** `<column> LIKE ?`, case-insensitively, for whereRaw()/orWhereRaw(). */
    public static function like(string $column): string
    {
        return self::likeSql(DB::getQueryGrammar()->wrap($column));
    }

    /** Same as like() for an already-quoted SQL expression. */
    public static function likeSql(string $sqlExpression): string
    {
        return $sqlExpression.' COLLATE '.self::CASE_INSENSITIVE_COLLATION.' LIKE ?';
    }
}
