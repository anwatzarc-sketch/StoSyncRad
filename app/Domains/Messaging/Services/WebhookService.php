<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Services;

use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Messaging\Enums\WebhookDeliveryStatus as S;
use App\Domains\Messaging\Models\OutboxEvent;
use App\Domains\Messaging\Models\WebhookDelivery;
use App\Domains\Messaging\Models\WebhookEndpoint;
use App\Domains\Platform\Config\PlatformConfig;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Outbound webhooks (Chapter 11 §11.7): minimal payloads with resource links, HMAC-SHA256
 * signatures (`X-Webhook-Signature: t=<unix>,v1=<hex>` over "<t>.<body>"), exponential backoff,
 * dead-lettering, automatic endpoint suspension after repeated failures. Inbound carrier webhooks
 * are verified with the same scheme.
 */
final class WebhookService
{
    private const SUSPEND_AFTER = 25;

    public function __construct(private readonly PlatformConfig $config, private readonly AuditRecorder $audit) {}

    /**
     * @param  array<int, string>  $eventTypes  exact event types, `module.*` prefixes or `*`
     * @return array{endpoint: WebhookEndpoint, secret: string} the secret is shown once
     */
    public function register(string $name, string $url, array $eventTypes, UserAccount $actor): array
    {
        if (! preg_match('#^https://#i', $url) && ! app()->environment('local', 'testing')) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Webhook URLs must use HTTPS.', ['field' => 'url']);
        }
        $secret = 'whsec_'.Str::random(40);
        $endpoint = WebhookEndpoint::query()->forceCreate(['name' => $name, 'url' => $url, 'secret_encrypted' => $secret,
            'event_types' => array_values(array_unique($eventTypes)) ?: ['*'], 'is_active' => true, 'created_by' => $actor->id, 'updated_by' => $actor->id]);
        $this->audit->record($actor, 'messaging.webhook.registered', 'webhook_endpoints', $endpoint->id, null, ['name' => $name, 'url' => $url, 'events' => $endpoint->event_types]);

        return ['endpoint' => $endpoint, 'secret' => $secret];
    }

    public function rotateSecret(string $endpointId, UserAccount $actor): string
    {
        $secret = 'whsec_'.Str::random(40);
        WebhookEndpoint::query()->findOrFail($endpointId)->forceFill(['secret_encrypted' => $secret, 'updated_by' => $actor->id])->save();
        $this->audit->record($actor, 'messaging.webhook.secret_rotated', 'webhook_endpoints', $endpointId);

        return $secret;
    }

    /** Create a pending delivery per subscribed endpoint (called by the outbox relay). */
    public function enqueue(OutboxEvent $event): int
    {
        if (! $this->config->bool('integration.webhooks.enabled')) {
            return 0;
        }
        $n = 0;
        foreach (WebhookEndpoint::query()->where('is_active', true)->get() as $endpoint) {
            if (! $this->subscribed($endpoint->event_types, $event->event_type)) {
                continue;
            }
            $n += DB::table('webhook_deliveries')->insertOrIgnore(['id' => (string) Str::uuid7(), 'webhook_endpoint_id' => $endpoint->id,
                'outbox_event_id' => $event->id, 'status' => S::PENDING->value, 'attempts' => 0, 'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }

        return $n;
    }

    /** Deliver due webhooks (scheduler / worker). @return array{delivered: int, failed: int, dead: int} */
    public function deliverDue(int $limit = 100): array
    {
        $stats = ['delivered' => 0, 'failed' => 0, 'dead' => 0];
        $max = $this->config->int('integration.webhooks.max_attempts');
        $ids = WebhookDelivery::query()->whereIn('status', [S::PENDING->value, S::FAILED->value])->where('next_attempt_at', '<=', now())
            ->orderBy('next_attempt_at')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            $delivery = DB::transaction(function () use ($id) {
                $d = WebhookDelivery::query()->whereKey($id)->whereIn('status', [S::PENDING->value, S::FAILED->value])->lockForUpdate()->first();
                $d?->forceFill(['next_attempt_at' => now()->addMinutes(5)])->save();   // lease against parallel workers

                return $d;
            });
            if ($delivery === null) {
                continue;
            }
            $endpoint = WebhookEndpoint::query()->find($delivery->webhook_endpoint_id);
            $event = OutboxEvent::query()->find($delivery->outbox_event_id);
            if ($endpoint === null || $event === null || ! $endpoint->is_active) {
                $delivery->forceFill(['status' => S::DEAD, 'last_error' => 'Endpoint inactive'])->save();
                $stats['dead']++;

                continue;
            }
            $body = json_encode(['id' => $event->id, 'type' => $event->event_type, 'occurred_at' => $event->created_at?->toIso8601String(),
                'resource' => ['type' => $event->aggregate_type, 'id' => $event->aggregate_id], 'data' => $event->payload,
                'correlation_id' => $event->correlation_id], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $code = null;
            $error = null;
            try {
                $response = Http::timeout(10)->connectTimeout(5)->withHeaders([
                    'Content-Type' => 'application/json', 'X-Webhook-Id' => $delivery->id, 'X-Webhook-Event' => $event->event_type,
                    'X-Webhook-Signature' => self::sign($endpoint->secret_encrypted, $body, time()),
                ])->withBody($body, 'application/json')->post($endpoint->url);
                $code = $response->status();
                $ok = $response->successful();
                $error = $ok ? null : 'HTTP '.$code;
            } catch (\Throwable $e) {
                $ok = false;
                $error = mb_substr($e->getMessage(), 0, 500);
            }
            $attempts = $delivery->attempts + 1;
            if ($ok) {
                $delivery->forceFill(['status' => S::DELIVERED, 'attempts' => $attempts, 'last_status_code' => $code, 'last_error' => null, 'delivered_at' => now()])->save();
                $endpoint->forceFill(['consecutive_failures' => 0, 'last_success_at' => now()])->save();
                $stats['delivered']++;

                continue;
            }
            $dead = $attempts >= $max;
            $delivery->forceFill(['status' => $dead ? S::DEAD : S::FAILED, 'attempts' => $attempts, 'last_status_code' => $code, 'last_error' => $error,
                'next_attempt_at' => now()->addSeconds(min(6 * 3600, 30 * (2 ** ($attempts - 1))))])->save();
            $failures = $endpoint->consecutive_failures + 1;
            $endpoint->forceFill(['consecutive_failures' => $failures] + ($failures >= self::SUSPEND_AFTER ? ['is_active' => false, 'disabled_at' => now()] : []))->save();
            $stats[$dead ? 'dead' : 'failed']++;
        }

        return $stats;
    }

    public function retry(string $deliveryId, UserAccount $actor): WebhookDelivery
    {
        $d = WebhookDelivery::query()->findOrFail($deliveryId);
        $d->forceFill(['status' => S::PENDING, 'attempts' => 0, 'next_attempt_at' => now()])->save();
        $this->audit->record($actor, 'messaging.webhook.retried', 'webhook_deliveries', $d->id);

        return $d;
    }

    public static function sign(string $secret, string $body, int $timestamp): string
    {
        return "t=$timestamp,v1=".hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /** Verify an inbound signature (carrier webhooks) with a 5-minute replay window. */
    public static function verify(?string $header, string $body, string $secret, int $toleranceSeconds = 300): bool
    {
        if ($header === null || $secret === '' || ! preg_match('/t=(\d+),v1=([a-f0-9]{64})/', $header, $m)) {
            return false;
        }
        if (abs(time() - (int) $m[1]) > $toleranceSeconds) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $m[1].'.'.$body, $secret), $m[2]);
    }

    /** @param  array<int, string>  $patterns */
    private function subscribed(array $patterns, string $type): bool
    {
        foreach ($patterns as $p) {
            if ($p === '*' || $p === $type || (str_ends_with($p, '.*') && str_starts_with($type, substr($p, 0, -1)))) {
                return true;
            }
        }

        return false;
    }
}
