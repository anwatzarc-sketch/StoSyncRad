<?php

declare(strict_types=1);

namespace App\Domains\Audit\Models;

use App\Domains\Audit\Enums\AuditActorType;
use App\Support\Eloquent\BaseModel;

/**
 * Append-only audit record (Chapter 14 §14.6). Written only through AuditRecorder.
 *
 * @property string $id
 * @property AuditActorType $actor_type
 * @property string|null $actor_account_id
 * @property string $event_type
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property array<string,mixed>|null $before
 * @property array<string,mixed>|null $after
 * @property array<string,mixed>|null $context
 * @property string|null $reason
 * @property string $correlation_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property bool $is_failed_attempt
 * @property \Carbon\CarbonImmutable $occurred_at
 */
final class AuditEvent extends BaseModel
{
    protected $table = 'audit_events';

    public $timestamps = false;

    protected $fillable = [
        'actor_type', 'actor_account_id', 'event_type', 'subject_type', 'subject_id', 'before', 'after', 'context',
        'reason', 'correlation_id', 'ip_address', 'user_agent', 'is_failed_attempt', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'actor_type' => AuditActorType::class,
            'before' => 'array',
            'after' => 'array',
            'context' => 'array',
            'is_failed_attempt' => 'boolean',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
