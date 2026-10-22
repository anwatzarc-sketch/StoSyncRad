<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Database\QueryException;
use PDOException;
use Throwable;

/**
 * Classifies MySQL errors by driver error number (Chapter 09 §9.8). SQLSTATE alone is ambiguous on
 * MySQL: duplicate keys and foreign-key failures share 23000, and timeouts and trigger SIGNALs share
 * HY000/45000, so callers decide on the error number.
 */
final class SqlError
{
    public const DUPLICATE_KEY = 1062;

    public const ROW_IS_REFERENCED = 1451;

    public const REFERENCED_ROW_MISSING = 1452;

    public const CHECK_VIOLATION = 3819;

    public const DEADLOCK = 1213;

    public const LOCK_WAIT_TIMEOUT = 1205;

    public const LOCK_NOWAIT = 3572;

    public const STATEMENT_TIMEOUT = 3024;

    public const QUERY_INTERRUPTED = 1317;

    public const SIGNAL = 1644;

    public const TABLE_ACCESS_DENIED = 1142;

    public const COLUMN_ACCESS_DENIED = 1143;

    /** Message prefix raised by the append-only triggers (Ddl::appendOnly). */
    public const APPEND_ONLY_VIOLATION = 'APPEND_ONLY_VIOLATION';

    public static function errno(Throwable $e): ?int
    {
        for ($cur = $e; $cur !== null; $cur = $cur->getPrevious()) {
            if (($cur instanceof QueryException || $cur instanceof PDOException) && isset($cur->errorInfo[1])) {
                return (int) $cur->errorInfo[1];
            }
        }

        return null;
    }

    /** The server's own message text, without Laravel's "(Connection: …, SQL: …)" suffix. */
    public static function serverMessage(Throwable $e): ?string
    {
        for ($cur = $e; $cur !== null; $cur = $cur->getPrevious()) {
            if (($cur instanceof QueryException || $cur instanceof PDOException) && isset($cur->errorInfo[2])) {
                return (string) $cur->errorInfo[2];
            }
        }

        return null;
    }

    public static function sqlState(Throwable $e): ?string
    {
        for ($cur = $e; $cur !== null; $cur = $cur->getPrevious()) {
            if ($cur instanceof QueryException || $cur instanceof PDOException) {
                return (string) ($cur->errorInfo[0] ?? $cur->getCode());
            }
        }

        return null;
    }

    public static function is(Throwable $e, int ...$errnos): bool
    {
        return in_array(self::errno($e), $errnos, true);
    }

    /** InnoDB already rolled the whole transaction back; it is safe to retry from the start. */
    public static function isDeadlock(Throwable $e): bool
    {
        return self::is($e, self::DEADLOCK);
    }

    public static function isLockTimeout(Throwable $e): bool
    {
        return self::is($e, self::LOCK_WAIT_TIMEOUT, self::LOCK_NOWAIT);
    }

    public static function isDuplicateKey(Throwable $e): bool
    {
        return self::is($e, self::DUPLICATE_KEY);
    }

    public static function isForeignKeyViolation(Throwable $e): bool
    {
        return self::is($e, self::ROW_IS_REFERENCED, self::REFERENCED_ROW_MISSING);
    }

    public static function isCheckViolation(Throwable $e): bool
    {
        return self::is($e, self::CHECK_VIOLATION);
    }

    /** The statement exceeded max_execution_time. */
    public static function isStatementTimeout(Throwable $e): bool
    {
        return self::is($e, self::STATEMENT_TIMEOUT);
    }

    /** The statement was stopped with KILL QUERY (report cancellation). */
    public static function isCancelled(Throwable $e): bool
    {
        return self::is($e, self::QUERY_INTERRUPTED);
    }

    public static function isAccessDenied(Throwable $e): bool
    {
        return self::is($e, self::TABLE_ACCESS_DENIED, self::COLUMN_ACCESS_DENIED);
    }

    public static function isAppendOnlyViolation(Throwable $e): bool
    {
        return self::is($e, self::SIGNAL) && str_contains(self::serverMessage($e) ?? $e->getMessage(), self::APPEND_ONLY_VIOLATION);
    }

    /** Name of the unique index, CHECK or foreign key named in the error message, when present. */
    public static function constraintName(Throwable $e): ?string
    {
        $message = self::serverMessage($e) ?? $e->getMessage();
        $pattern = match (self::errno($e)) {
            // Anchored at the end: the duplicated value earlier in the message is user input.
            self::DUPLICATE_KEY => "/for key '(?:[^'.]+\\.)?([^']+)'$/",
            self::CHECK_VIOLATION => "/[Cc]heck constraint '([^']+)'/",
            self::ROW_IS_REFERENCED, self::REFERENCED_ROW_MISSING => '/CONSTRAINT `([^`]+)`/',
            default => null,
        };

        return $pattern !== null && preg_match($pattern, $message, $m) === 1 ? $m[1] : null;
    }
}
