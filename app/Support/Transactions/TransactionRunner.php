<?php

declare(strict_types=1);

namespace App\Support\Transactions;

use App\Domains\Platform\Config\PlatformConfig;
use App\Support\Database\MySqlConnection;
use App\Support\Database\SqlError;
use App\Support\Exceptions\DomainException;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs a closure in a MySQL transaction with bounded full-transaction retries for recognised
 * transient concurrency failures only (Chapter 09 §9.2, §9.8): InnoDB deadlocks (1213), after which
 * the server has already rolled the whole transaction back. Business exceptions, uniqueness
 * violations, lock-wait timeouts and validation errors are never retried. Each retry starts from the
 * beginning with fresh reads.
 */
final class TransactionRunner
{
    /** @var int number of retries performed by the last run (observability/tests) */
    public int $lastRetries = 0;

    public function __construct(private readonly PlatformConfig $config) {}

    /**
     * @template T
     *
     * @param  Closure(ConnectionInterface): T  $work
     * @return T
     */
    public function run(Closure $work, ?int $maxAttempts = null, bool $serializable = false, ?string $connection = null): mixed
    {
        $maxAttempts ??= max(1, $this->config->int('db.transaction.max_attempts'));
        $backoffMs = max(0, $this->config->int('db.transaction.backoff_ms'));
        $db = DB::connection($connection);
        $this->lastRetries = 0;

        // Session settings must be in place before BEGIN; only the outermost caller changes them.
        $owns = $db->transactionLevel() === 0;
        if ($owns) {
            $db->unprepared('SET SESSION innodb_lock_wait_timeout = '.MySqlConnection::lockWaitSeconds($this->config->int('db.lock_timeout_ms')));
        }

        try {
            for ($attempt = 1; ; $attempt++) {
                try {
                    if ($owns && $serializable) {
                        $db->unprepared('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');   // applies to the next transaction only
                    }

                    return $db->transaction(fn (ConnectionInterface $conn) => $work($conn));
                } catch (Throwable $e) {
                    if (! self::isRetryable($e) || $db->transactionLevel() > 0 || $attempt >= $maxAttempts) {
                        if (self::isRetryable($e) && $attempt >= $maxAttempts) {
                            Log::warning('db.transaction.retries_exhausted', ['attempts' => $attempt, 'errno' => SqlError::errno($e)]);
                            throw DomainException::retriesExhausted();
                        }
                        if (SqlError::isLockTimeout($e)) {
                            throw DomainException::lockTimeout();
                        }
                        throw $e;
                    }
                    $this->lastRetries++;
                    Log::info('db.transaction.retry', ['attempt' => $attempt, 'errno' => SqlError::errno($e)]);
                    if ($backoffMs > 0) {
                        usleep((int) (($backoffMs * (2 ** ($attempt - 1)) + random_int(0, $backoffMs)) * 1000));
                    }
                }
            }
        } finally {
            if ($owns) {
                $this->restoreLockWait($db);
            }
        }
    }

    public static function isRetryable(Throwable $e): bool
    {
        return SqlError::isDeadlock($e);
    }

    private function restoreLockWait(ConnectionInterface $db): void
    {
        $configured = (int) config('database.timeouts.'.$db->getName().'.lock_timeout', 0);
        if ($configured <= 0) {
            return;
        }
        try {
            $db->unprepared('SET SESSION innodb_lock_wait_timeout = '.MySqlConnection::lockWaitSeconds($configured));
        } catch (Throwable) {
            // The connection may be gone; it re-applies its settings when it reconnects.
        }
    }
}
