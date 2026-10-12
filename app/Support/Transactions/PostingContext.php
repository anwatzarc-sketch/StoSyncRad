<?php

declare(strict_types=1);

namespace App\Support\Transactions;

use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Messaging\Services\Outbox;
use App\Support\Testing\FaultInjector;
use Carbon\CarbonImmutable;

/**
 * Per-attempt context handed to the work closure of a PostingPipeline run. Everything written
 * through it shares the posting transaction, so it commits or rolls back with the business data.
 */
final class PostingContext
{
    public function __construct(
        public readonly ?UserAccount $actor,
        public readonly string $operation,
        public readonly ?string $idempotencyKeyId,
        public readonly string $correlationId,
        public readonly CarbonImmutable $now,
        public readonly int $attempt,
        private readonly AuditRecorder $audit,
        private readonly Outbox $outbox,
    ) {}

    /**
     * @param  array<string,mixed>|null  $before
     * @param  array<string,mixed>|null  $after
     * @param  array<string,mixed>  $context
     */
    public function audit(string $eventType, ?string $subjectType, ?string $subjectId, ?array $before = null, ?array $after = null, ?string $reason = null, array $context = []): void
    {
        $this->audit->record($this->actor, $eventType, $subjectType, $subjectId, $before, $after, $reason, $context);
        FaultInjector::point('after_audit');
    }

    /** @param  array<string,mixed>  $payload */
    public function publish(string $eventType, string $aggregateType, string $aggregateId, array $payload = []): void
    {
        $this->outbox->publish($eventType, $aggregateType, $aggregateId, $payload);
    }

    public function actorId(): ?string
    {
        return $this->actor?->id;
    }
}
