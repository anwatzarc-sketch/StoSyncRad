<?php

declare(strict_types=1);

namespace App\Support\Transactions;

use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Messaging\Services\Outbox;
use App\Domains\Platform\Config\PlatformConfig;
use App\Support\Database\SqlError;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Http\Correlation;
use App\Support\Testing\FaultInjector;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The mandatory posting sequence (Chapter 09 §9.2):
 *
 *  1. validate the idempotency key                    (here)
 *  2. authenticate & authorize                        (caller, before run())
 *  3. idempotency pre-check → replay / mismatch        (here, outside the transaction)
 *  4. lock-wait timeout, begin transaction             (TransactionRunner)
 *  5. claim the key: INSERT … ON DUPLICATE KEY UPDATE  (here, inside the transaction; the
 *                                                       unique constraint is the race breaker)
 *  6–12. lock document, lock positions, revalidate, write ledger, projections, audit, outbox
 *                                                      (the work closure, via InventoryPoster)
 *  13. complete the key with the response              (here)
 *  14. commit                                          (TransactionRunner)
 *  15. return — only after commit                      (here)
 *
 * Deadlocks/serialization failures retry the whole transaction (the claimed key rolls back with
 * it). Any other failure rolls back everything and writes a failed-attempt audit record on a
 * separate connection; the failure never reads as success.
 */
final class PostingPipeline
{
    public function __construct(
        private readonly TransactionRunner $runner,
        private readonly AuditRecorder $audit,
        private readonly Outbox $outbox,
        private readonly PlatformConfig $config,
    ) {}

    /**
     * @param  array<string,mixed>  $payload  canonical request payload (for the request hash)
     * @param  Closure(PostingContext): PostingOutcome  $work
     * @param  array{0: string, 1: ?string, 2: ?string}|null  $failureAudit  [eventType, subjectType, subjectId]
     */
    public function run(
        ?UserAccount $actor,
        string $operation,
        ?string $idempotencyKey,
        array $payload,
        Closure $work,
        ?array $failureAudit = null,
        IdempotencyScopeType $scopeType = IdempotencyScopeType::ACCOUNT,
        ?string $scopeId = null,
        bool $serializable = false,
    ): PostingOutcome {
        if ($idempotencyKey === null || ! Str::isUuid($idempotencyKey)) {
            throw DomainException::of(ErrorCode::IDEMPOTENCY_KEY_REQUIRED,
                'Posting operations require an Idempotency-Key header containing a UUID.', [],
                'Generate one UUID per logical attempt and reuse it when retrying.');
        }
        $idempotencyKey = strtolower($idempotencyKey);
        $scopeId ??= $actor?->id ?? 'system';
        $hash = self::hash($operation, $payload);

        // Step 3 — pre-check outside the transaction (cheap replay path).
        if (($replay = $this->lookup($scopeType, $scopeId, $idempotencyKey, $hash)) !== null) {
            return $replay;
        }

        $attempt = 0;
        try {
            return $this->runner->run(function (ConnectionInterface $conn) use (
                $actor, $operation, $idempotencyKey, $work, $scopeType, $scopeId, $hash, &$attempt
            ) {
                $attempt++;
                $keyId = (string) Str::uuid7();
                $now = now()->toImmutable();
                $ttl = $this->config->int('api.idempotency.ttl_hours');

                // Step 5 — claim. Blocks behind a concurrent uncommitted claim of the same key;
                // after it commits the insert hits the duplicate (now locked by us) and we replay its
                // result. One affected row = inserted; zero = the key already existed. Unlike
                // INSERT IGNORE this keeps CHECK and truncation errors visible.
                $claimed = $conn->affectingStatement(
                    'INSERT INTO idempotency_keys (id, scope_type, scope_id, `key`, operation, request_hash, status, expires_at, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE id = id',
                    [$keyId, $scopeType->value, $scopeId, $idempotencyKey, $operation, $hash, IdempotencyStatus::IN_PROGRESS->value,
                        $now->addHours($ttl), $now, $now]
                ) === 1;
                if (! $claimed) {
                    $existing = $this->lookup($scopeType, $scopeId, $idempotencyKey, $hash, $conn);
                    if ($existing !== null) {
                        return $existing;
                    }
                    throw DomainException::of(ErrorCode::IDEMPOTENT_IN_PROGRESS,
                        'An operation with this Idempotency-Key is still being processed.', [],
                        'Retry shortly with the same Idempotency-Key, or query /api/v1/operations/{key}.');
                }

                $ctx = new PostingContext($actor, $operation, $keyId, Correlation::id(app()->runningInConsole() ? null : request()), $now, $attempt, $this->audit, $this->outbox);
                $outcome = $work($ctx);

                // Step 13 — complete the key inside the same transaction.
                $conn->update(
                    'UPDATE idempotency_keys SET status = ?, response_code = ?, response_body = ?, result_document_type = ?, result_document_id = ?, updated_at = ? WHERE id = ?',
                    [IdempotencyStatus::COMPLETED->value, $outcome->status, json_encode($outcome->body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                        $outcome->documentType, $outcome->documentId, now(), $keyId]
                );

                FaultInjector::point('before_commit');

                return new PostingOutcome($outcome->body, $outcome->status, $outcome->documentType, $outcome->documentId, false, $attempt - 1);
            }, serializable: $serializable);
        } catch (Throwable $e) {
            if ($failureAudit !== null && ! ($e instanceof DomainException && $e->errorCode === ErrorCode::IDEMPOTENCY_PAYLOAD_MISMATCH)) {
                [$event, $subjectType, $subjectId] = $failureAudit;
                $this->audit->failedAttempt($actor, $event, $subjectType, $subjectId, $e, [
                    'operation' => $operation, 'idempotency_key' => $idempotencyKey, 'attempts' => $attempt,
                ]);
            }
            throw $e;
        }
    }

    /** Outcome lookup used by GET /api/v1/operations/{key} (Chapter 09 §9.10). */
    public function status(IdempotencyScopeType $scopeType, string $scopeId, string $key): ?IdempotencyKey
    {
        return IdempotencyKey::query()
            ->where('scope_type', $scopeType->value)->where('scope_id', $scopeId)->where('key', strtolower($key))
            ->first();
    }

    /** @param  array<string,mixed>  $payload */
    public static function hash(string $operation, array $payload): string
    {
        return hash('sha256', $operation.'|'.json_encode(self::canonical($payload), JSON_UNESCAPED_UNICODE));
    }

    private function lookup(IdempotencyScopeType $scopeType, string $scopeId, string $key, string $hash, ?ConnectionInterface $conn = null): ?PostingOutcome
    {
        $row = ($conn ?? DB::connection())->selectOne(
            'SELECT status, request_hash, response_code, response_body, result_document_type, result_document_id
               FROM idempotency_keys WHERE scope_type = ? AND scope_id = ? AND `key` = ?',
            [$scopeType->value, $scopeId, $key]
        );
        if ($row === null) {
            return null;
        }
        if ($row->request_hash !== $hash) {
            throw DomainException::of(ErrorCode::IDEMPOTENCY_PAYLOAD_MISMATCH,
                'This Idempotency-Key was already used with a different request payload.', [],
                'Use a new Idempotency-Key for a different request.');
        }
        if ($row->status === IdempotencyStatus::COMPLETED->value) {
            return new PostingOutcome(
                json_decode((string) $row->response_body, true) ?? [],
                200, $row->result_document_type, $row->result_document_id, true
            );
        }

        return null;
    }

    private static function canonical(mixed $v): mixed
    {
        if (! is_array($v)) {
            return $v;
        }
        if (! array_is_list($v)) {
            ksort($v);
        }

        return array_map(self::canonical(...), $v);
    }

    /** Convenience for callers translating a lock wait on the key into the documented error. */
    public static function isLockTimeout(Throwable $e): bool
    {
        return SqlError::isLockTimeout($e);
    }
}
