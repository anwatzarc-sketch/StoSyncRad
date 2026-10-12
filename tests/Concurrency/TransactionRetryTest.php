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
 * Chapter 09 §9.8 — only deadlocks (40P01) and serialization failures (40001) are retried, from
 * the beginning of the transaction, a bounded number of times; nothing from a failed attempt
 * survives. Runs outside a wrapping transaction because retries need top-level transactions.
 */
final class TransactionRetryTest extends TestCase
{
    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    public function test_deadlock_is_retried_from_the_beginning_without_duplicating_effects(): void
    {
        $key = (string) Str::uuid7();
        $aggregate = (string) Str::uuid7();
        $attempts = 0;

        $outcome = app(PostingPipeline::class)->run(null, 'test.retry', $key, ['a' => 1], function (PostingContext $ctx) use (&$attempts, $aggregate) {
            $attempts++;
            $ctx->publish('test.retry.effect', 'test', $aggregate);   // transactional effect
            if ($attempts === 1) {
                throw $this->sqlError('40P01', 'deadlock detected');
            }

            return new PostingOutcome(['attempts' => $attempts]);
        });

        $this->assertSame(2, $attempts);
        $this->assertSame(1, $outcome->retries);
        $this->assertSame(1, DB::table('outbox_events')->where('aggregate_id', $aggregate)->count(), 'effects of the failed attempt must roll back');
        $this->assertSame(1, DB::table('idempotency_keys')->where('key', $key)->count());
    }

    public function test_serialization_failure_is_retried(): void
    {
        $attempts = 0;
        app(TransactionRunner::class)->run(function () use (&$attempts) {
            if (++$attempts < 3) {
                throw $this->sqlError('40001', 'could not serialize access');
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
                throw $this->sqlError('40P01', 'deadlock detected');
            }, maxAttempts: 3);
            $this->fail('Expected CONCURRENCY_RETRIES_EXHAUSTED');
        } catch (DomainException $e) {
            $this->assertSame(ErrorCode::CONCURRENCY_RETRIES_EXHAUSTED, $e->errorCode);
        }
        $this->assertSame(3, $attempts);
    }

    public function test_non_transient_errors_are_never_retried(): void
    {
        foreach ([['23505', 'duplicate key'], ['23514', 'check constraint'], ['42501', 'permission denied']] as [$state, $message]) {
            $attempts = 0;
            try {
                app(TransactionRunner::class)->run(function () use (&$attempts, $state, $message) {
                    $attempts++;
                    throw $this->sqlError($state, $message);
                }, maxAttempts: 3);
            } catch (QueryException $e) {
                $this->assertSame($state, $e->errorInfo[0]);
            }
            $this->assertSame(1, $attempts, "$state must not be retried");
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
            app(TransactionRunner::class)->run(fn () => throw $this->sqlError('55P03', 'canceling statement due to lock timeout'));
            $this->fail('Expected LOCK_TIMEOUT');
        } catch (DomainException $e) {
            $this->assertSame(ErrorCode::LOCK_TIMEOUT, $e->errorCode);
        }
    }

    private function sqlError(string $sqlState, string $message): QueryException
    {
        $pdo = new PDOException("SQLSTATE[$sqlState]: $message");
        $pdo->errorInfo = [$sqlState, 7, $message];

        return new QueryException('pgsql', 'select 1', [], $pdo);
    }
}
