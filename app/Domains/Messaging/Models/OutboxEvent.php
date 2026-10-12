<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Models;

use App\Domains\Messaging\Enums\OutboxStatus;
use App\Support\Eloquent\BaseModel;

/**
 * Transactional outbox row (Chapter 09 §9.4).
 *
 * @property string $id
 * @property string $event_type
 * @property string $aggregate_type
 * @property string $aggregate_id
 * @property array<string,mixed> $payload
 * @property string $correlation_id
 * @property OutboxStatus $status
 * @property int $attempts
 * @property \Carbon\CarbonImmutable $available_at
 * @property \Carbon\CarbonImmutable|null $processed_at
 * @property string|null $last_error
 */
final class OutboxEvent extends BaseModel
{
    protected $table = 'outbox_events';

    protected $fillable = ['event_type', 'aggregate_type', 'aggregate_id', 'payload', 'correlation_id', 'status', 'attempts', 'available_at', 'processed_at', 'last_error'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => OutboxStatus::class,
            'attempts' => 'integer',
            'available_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }
}
