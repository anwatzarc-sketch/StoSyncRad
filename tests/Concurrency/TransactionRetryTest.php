<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Transactions\PostingContext;
use App\Support\Transactions\PostingOutcome;
use App\Support\Transactions\PostingPipeline;
use App\Support\Transactions\TransactionRunner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDOException;
use Tests\TestCase;

/**
 * Chapter 09 §9.8 — only InnoDB deadlocks (1213) are retried, from the beginning of the transaction,
 * a bounded number of times; nothing from a failed attempt survives. Runs outside a wrapping
 * transaction because retries need top-level transactions.
 */
final class TransactionRetryTest extends TestCase
{
    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private const DEADLOCK = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];

    public function test_deadlock_is_retried_from_the_beginning_without_duplicating_effects(): void
    {
        $key = (string) Str::uuid7();
        $aggregate = (string) Str::uuid7();
        $attempts = 0;

        $outcome = app(PostingPipeline::class)->run(null, 'test.retry', $key, ['a' => 1], function (PostingContext $ctx) use (&$attempts, $aggregate) {
            $attempts++;
            $ctx->publish('test.retry.effect', 'test', $aggregate);   // transactional effect
            if ($attempts === 1) {
                throw $this->sqlError(...self::DEADLOCK);
            }

            return new PostingOutcome(['attempts' => $attempts]);
        });

        $this->assertSame(2, $attempts);
        $this->assertSame(1, $outcome->retries);
        $this->assertSame(1, DB::table('outbox_events')->where('aggregate_id', $aggregate)->count(), 'effects of the failed attempt must roll back');
        $this->assertSame(1, DB::table('idempotency_keys')->where('key', $key)->count());
    }

    public function test_repeated_deadlocks_are_retried_until_one_attempt_succeeds(): void
    {
        $attempts = 0;
        app(TransactionRunner::class)->run(function () use (&$attempts) {
            if (++$attempts < 3) {
                throw $this->sqlError(...self::DEADLOCK);
            }
        }, maxAttempts: 3);
        $this->assertSame(3, $attempts);
    }

    public function test_retries_are_bounded_and_end_in_a_controlled_error(): void
    {
        $attempts = 0;
        try {
            app(TransactionRunner::class)->run(function () use (&$attempts) {
                $attempts++;
                throw $this->sqlError(...self::DEADLOCK);
            }, maxAttempts: 3);
            $this->fail('Expected CONCURRENCY_RETRIES_EXHAUSTED');
        } catch (DomainException $e) {
            $this->assertSame(ErrorCode::CONCURRENCY_RETRIES_EXHAUSTED, $e->errorCode);
        }
        $this->assertSame(3, $attempts);
    }

    public function test_non_transient_errors_are_never_retried(): void
    {
        $errors = [
            ['23000', 1062, "Duplicate entry 'x' for key 'items.items_sku_unique'"],
            ['HY000', 3819, "Check constraint 'chk_sml_positive_qty' is violated."],
            ['42000', 1142, "UPDATE command denied to user 'stosyncrad_app'@'%' for table 'stock_movement_lines'"],
        ];
        foreach ($errors as [$state, $errno, $message]) {
            $attempts = 0;
            try {
                app(TransactionRunner::class)->run(function () use (&$attempts, $state, $errno, $message) {
                    $attempts++;
                    throw $this->sqlError($state, $errno, $message);
                }, maxAttempts: 3);
            } catch (QueryException $e) {
                $this->assertSame($errno, $e->errorInfo[1]);
            }
            $this->assertSame(1, $attempts, "$errno must not be retried");
        }

        $attempts = 0;
        try {
            app(TransactionRunner::class)->run(function () use (&$attempts) {
                $attempts++;
                throw DomainException::insufficientStock('X', '2', '1');
            }, maxAttempts: 3);
        } catch (DomainException $e) {
            $this->assertSame(ErrorCode::INSUFFICIENT_STOCK, $e->errorCode);
        }
        $this->assertSame(1, $attempts, 'business errors must not be retried');
    }

    public function test_lock_timeout_is_reported_as_a_controlled_conflict(): void
    {
        try {
            app(TransactionRunner::class)->run(fn () => throw $this->sqlError('HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction'));
            $this->fail('Expected LOCK_TIMEOUT');
        } catch (DomainException $e) {
            $this->assertSame(ErrorCode::LOCK_TIMEOUT, $e->errorCode);
        }
    }

    /**
     * A lock-wait timeout rolls back only the failed statement on MySQL. Inside a nested transaction
     * the savepoint must still be rolled back, so the caller never keeps half of the nested work.
     */
    public function test_a_lock_wait_timeout_in_a_nested_transaction_rolls_back_to_its_savepoint(): void
    {
        $outer = 'test-outer-'.Str::uuid7();
        $nested = 'test-nested-'.Str::uuid7();
        DB::transaction(function () use ($outer, $nested) {
            DB::table('advisory_locks')->insert(['lock_key' => $outer, 'created_at' => now()]);
            try {
                DB::transaction(function () use ($nested) {
                    DB::table('advisory_locks')->insert(['lock_key' => $nested, 'created_at' => now()]);
                    throw $this->sqlError('HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction');
                });
            } catch (QueryException) {
                // the caller handles the timeout and carries on with the outer transaction
            }
        });

        $this->assertTrue(DB::table('advisory_locks')->where('lock_key', $outer)->exists());
        $this->assertFalse(DB::table('advisory_locks')->where('lock_key', $nested)->exists(), 'nested work must be rolled back');
        DB::table('advisory_locks')->where('lock_key', $outer)->delete();
    }

    private function sqlError(string $sqlState, int $errno, string $message): QueryException
    {
        $pdo = new PDOException("SQLSTATE[$sqlState]: General error: $errno $message");
        $pdo->errorInfo = [$sqlState, $errno, $message];

        return new QueryException('mysql', 'select 1', [], $pdo);
    }
}
