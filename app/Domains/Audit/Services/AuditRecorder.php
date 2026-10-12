<?php

declare(strict_types=1);

namespace App\Domains\Audit\Services;

use App\Domains\Audit\Enums\AuditActorType;
use App\Domains\Audit\Models\AuditEvent;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Support\Http\Correlation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The only writer of audit_events (Chapter 14 §14.6).
 *
 * record()        — inside the caller's transaction (success events roll back with it).
 * failedAttempt() — after a rollback, on a separate connection so the record survives
 *                   (Chapter 09 §9.9). Never implies the operation succeeded.
 */
final class AuditRecorder
{
    /** @var array<int, string> keys masked in snapshots */
    private const MASKED = ['password', 'password_hash', 'secret', 'token', 'mfa_secret', 'national_id', 'recovery_codes', 'client_secret'];

    /**
     * @param  array<string,mixed>|null  $before
     * @param  array<string,mixed>|null  $after
     * @param  array<string,mixed>  $context
     */
    public function record(
        ?UserAccount $actor,
        string $eventType,
        ?string $subjectType = null,
        ?string $subjectId = null,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        array $context = [],
        AuditActorType $actorType = AuditActorType::ACCOUNT,
        bool $isFailedAttempt = false,
        ?string $connection = null,
    ): AuditEvent {
        $request = app()->bound('request') && app()->runningInConsole() === false ? request() : null;

        $event = new AuditEvent([
            'actor_type' => $actor === null && $actorType === AuditActorType::ACCOUNT ? AuditActorType::SYSTEM : $actorType,
            'actor_account_id' => $actor?->id,
            'event_type' => $eventType,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'before' => $before === null ? null : $this->mask($before),
            'after' => $after === null ? null : $this->mask($after),
            'context' => $context === [] ? null : $this->mask($context),
            'reason' => $reason,
            'correlation_id' => Correlation::id($request),
            'ip_address' => $request?->ip(),
            'user_agent' => $request !== null ? Str::limit((string) $request->userAgent(), 250, '') : null,
            'is_failed_attempt' => $isFailedAttempt,
            'occurred_at' => now(),
        ]);

        if ($connection !== null) {
            $event->setConnection($connection);
        }
        $event->save();

        return $event;
    }

    /**
     * Persist a failed-attempt record outside the (rolled back) business transaction.
     * Falls back to the protected log when even that write fails.
     *
     * @param  array<string,mixed>  $context
     */
    public function failedAttempt(?UserAccount $actor, string $eventType, ?string $subjectType, ?string $subjectId, Throwable $cause, array $context = []): void
    {
        $connection = (string) config('platform.failed_attempt_audit_connection', 'pgsql_audit');
        $payload = ['error_class' => $cause::class, 'error_message' => Str::limit($cause->getMessage(), 500)] + $context;

        try {
            $this->record($actor, $eventType, $subjectType, $subjectId, null, null, null, $payload,
                isFailedAttempt: true, connection: $connection);
        } catch (Throwable $e) {
            Log::channel(config('logging.audit_fallback_channel', 'stack'))->error('audit.failed_attempt.unpersisted', [
                'event_type' => $eventType, 'subject_type' => $subjectType, 'subject_id' => $subjectId,
                'actor_account_id' => $actor?->id, 'cause' => $payload, 'audit_error' => $e->getMessage(),
            ]);
        }
    }

    public function accessDenied(?UserAccount $actor, string $permission, Request $request): void
    {
        try {
            $this->record($actor, 'identity.access.denied', 'permission', null, null, null, null, [
                'permission' => $permission, 'route' => $request->route()?->getName(), 'method' => $request->method(), 'path' => $request->path(),
            ], connection: (string) config('platform.failed_attempt_audit_connection', 'pgsql_audit'));
        } catch (Throwable $e) {
            Log::warning('audit.access_denied.unpersisted', ['permission' => $permission, 'error' => $e->getMessage()]);
        }
    }

    /** Make audit writes participate in the current test transaction. */
    public static function runningInTransaction(): bool
    {
        return DB::transactionLevel() > 0;
    }

    /** @param  array<string,mixed>  $data */
    private function mask(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->mask($value);
            } elseif (is_string($key) && in_array(strtolower($key), self::MASKED, true)) {
                $data[$key] = '***';
            }
        }

        return $data;
    }
}
