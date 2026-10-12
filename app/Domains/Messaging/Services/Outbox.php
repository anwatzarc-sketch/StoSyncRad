<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Services;

use App\Domains\Messaging\Enums\OutboxStatus;
use App\Domains\Messaging\Models\OutboxEvent;
use App\Support\Http\Correlation;

/**
 * Publishes events into the transactional outbox (Chapter 09 §9.4). The row is written in the
 * caller's transaction, so a rolled-back posting never emits an event. The relay worker
 * (outbox:relay) dispatches handlers after commit.
 */
final class Outbox
{
    /** @param  array<string,mixed>  $payload  keep minimal; receivers fetch details with their own credentials */
    public function publish(string $eventType, string $aggregateType, string $aggregateId, array $payload = []): OutboxEvent
    {
        return OutboxEvent::query()->create([
            'event_type' => $eventType,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'payload' => $payload,
            'correlation_id' => Correlation::id(app()->runningInConsole() ? null : request()),
            'status' => OutboxStatus::PENDING,
            'attempts' => 0,
            'available_at' => now(),
        ]);
    }
}
