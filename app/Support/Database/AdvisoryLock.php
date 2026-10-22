<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Database\ConnectionInterface;
use LogicException;

/**
 * Transaction-scoped named locks (the MySQL counterpart of pg_advisory_xact_lock). The lock is the
 * exclusive row lock on `advisory_locks.lock_key`, so it is released automatically when the enclosing
 * transaction commits or rolls back, and it takes part in InnoDB deadlock detection. GET_LOCK() is
 * deliberately not used: it is session-scoped and survives the transaction.
 */
final class AdvisoryLock
{
    private const MAX_KEY = 191;

    public static function forTransaction(ConnectionInterface $db, string $name): void
    {
        if ($db->transactionLevel() < 1) {
            throw new LogicException('Advisory locks must be taken inside a transaction.');
        }
        $key = strlen($name) <= self::MAX_KEY ? $name : 'h:'.hash('sha256', $name);

        // A new key is locked by its own insert; an existing key gets an exclusive record lock from the
        // duplicate-key path. Either way the lock lasts until COMMIT/ROLLBACK.
        $db->statement(
            'INSERT INTO advisory_locks (lock_key, created_at) VALUES (?, ?) ON DUPLICATE KEY UPDATE lock_key = lock_key',
            [$key, now()]
        );
    }
}
