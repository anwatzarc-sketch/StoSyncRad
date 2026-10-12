<?php

declare(strict_types=1);

namespace App\Support\Transactions;

use App\Domains\Platform\Config\PlatformConfig;
use App\Support\Exceptions\DomainException;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs a closure in a PostgreSQL transaction with bounded full-transaction retries for
 * recognised transient concurrency failures only (Chapter 09 §9.2, §9.8):
 *   40P01 deadlock_detected, 40001 serialization_failure.
 * Business exceptions, uniqueness violations, lock timeouts and validation errors are never
 * retried. Each retry starts from the beginning with fresh reads.
 */
final class TransactionRunner
{
    public const RETRYABLE_SQLSTATES = ['40P01', '40001'];

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
        $lockTimeoutMs = $this->config->int('db.lock_timeout_ms');
        $db = DB::connection($connection);
        $this->lastRetries = 0;

        for ($attempt = 1; ; $attempt++) {
            try {
                return $db->transaction(function (ConnectionInterface $conn) use ($work, $lockTimeoutMs, $serializable) {
                    if ($conn->transactionLevel() === 1) {
                        if ($serializable) {
                            $conn->unprepared('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
                        }
                        $conn->unprepared('SET LOCAL lock_timeout = '.(int) $lockTimeoutMs);
                    }

                    return $work($conn);
                });
            } catch (Throwable $e) {
                if (! self::isRetryable($e) || $db->transactionLevel() > 0 || $attempt >= $maxAttempts) {
                    if (self::isRetryable($e) && $attempt >= $maxAttempts) {
                        Log::warning('db.transaction.retries_exhausted', ['attempts' => $attempt, 'sqlstate' => self::sqlState($e)]);
                        throw DomainException::retriesExhausted();
                    }
                    if (self::sqlState($e) === '55P03') {
                        throw DomainException::lockTimeout();
                    }
                    throw $e;
                }
                $this->lastRetries++;
                Log::info('db.transaction.retry', ['attempt' => $attempt, 'sqlstate' => self::sqlState($e)]);
                if ($backoffMs > 0) {
                    usleep((int) (($backoffMs * (2 ** ($attempt - 1)) + random_int(0, $backoffMs)) * 1000));
                }
            }
        }
    }

    public static function isRetryable(Throwable $e): bool
    {
        return in_array(self::sqlState($e), self::RETRYABLE_SQLSTATES, true);
    }

    public static function sqlState(Throwable $e): ?string
    {
        for ($cur = $e; $cur !== null; $cur = $cur->getPrevious()) {
            if ($cur instanceof QueryException) {
                return (string) ($cur->errorInfo[0] ?? $cur->getCode());
            }
            if ($cur instanceof \PDOException) {
                return (string) ($cur->errorInfo[0] ?? $cur->getCode());
            }
        }

        return null;
    }
}
