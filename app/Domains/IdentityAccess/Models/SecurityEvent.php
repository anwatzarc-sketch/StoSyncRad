<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Models;

use App\Domains\IdentityAccess\Enums\SecurityEventType;
use App\Support\Eloquent\BaseModel;
use App\Support\Http\Correlation;

/**
 * Append-only security event (Chapter 14 §14.6).
 *
 * @property SecurityEventType $event_type
 * @property string|null $account_id
 * @property string|null $username_attempted
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property array<string,mixed>|null $context
 */
final class SecurityEvent extends BaseModel
{
    protected $table = 'security_events';

    public $timestamps = false;

    protected $fillable = ['event_type', 'account_id', 'username_attempted', 'ip_address', 'user_agent', 'context', 'correlation_id', 'occurred_at'];

    protected function casts(): array
    {
        return ['event_type' => SecurityEventType::class, 'context' => 'array', 'occurred_at' => 'immutable_datetime'];
    }

    /** @param  array<string,mixed>  $context */
    public static function log(SecurityEventType $type, ?string $accountId = null, ?string $usernameAttempted = null, array $context = []): self
    {
        $request = app()->runningInConsole() ? null : request();

        return self::query()->create([
            'event_type' => $type,
            'account_id' => $accountId,
            'username_attempted' => $usernameAttempted,
            'ip_address' => $request?->ip(),
            'user_agent' => $request !== null ? mb_substr((string) $request->userAgent(), 0, 255) : null,
            'context' => $context ?: null,
            'correlation_id' => Correlation::id($request),
            'occurred_at' => now(),
        ]);
    }
}
