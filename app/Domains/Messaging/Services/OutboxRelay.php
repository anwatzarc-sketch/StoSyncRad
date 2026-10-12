<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Services;

use App\Domains\Messaging\Enums\OutboxStatus as S;
use App\Domains\Messaging\Models\OutboxEvent;
use App\Domains\Platform\Config\PlatformConfig;
use Illuminate\Support\Facades\DB;

/**
 * Transactional outbox relay (Chapter 09): events written atomically with business changes are
 * claimed with SKIP LOCKED (safe with several workers), routed to notifications and webhook
 * deliveries, then marked processed. Failures back off and finally go to `dead`.
 */
final class OutboxRelay
{
    public function __construct(
        private readonly NotificationRouter $notifications,
        private readonly WebhookService $webhooks,
        private readonly PlatformConfig $config,
    ) {}

    /** @return array{processed: int, failed: int, dead: int, notifications: int, webhooks: int} */
    public function relay(int $limit = 200): array
    {
        $stats = ['processed' => 0, 'failed' => 0, 'dead' => 0, 'notifications' => 0, 'webhooks' => 0];
        $ids = DB::transaction(function () use ($limit) {
            $ids = DB::table('outbox_events')->whereIn('status', [S::PENDING->value, S::FAILED->value])->where('available_at', '<=', now())
                ->orderBy('available_at')->orderBy('id')->limit($limit)->lock('FOR UPDATE SKIP LOCKED')->pluck('id')->all();
            if ($ids !== []) {
                DB::table('outbox_events')->whereIn('id', $ids)->update(['status' => S::PROCESSING->value, 'updated_at' => now()]);
            }

            return $ids;
        });
        $max = $this->config->int('messaging.outbox.max_attempts');
        foreach ($ids as $id) {
            $event = OutboxEvent::query()->find($id);
            if ($event === null) {
                continue;
            }
            try {
                DB::transaction(function () use ($event, &$stats) {
                    $stats['notifications'] += $this->notifications->route($event);
                    $stats['webhooks'] += $this->webhooks->enqueue($event);
                    $event->forceFill(['status' => S::PROCESSED, 'processed_at' => now(), 'attempts' => $event->attempts + 1, 'last_error' => null])->save();
                });
                $stats['processed']++;
            } catch (\Throwable $e) {
                $attempts = $event->attempts + 1;
                $dead = $attempts >= $max;
                $event->forceFill(['status' => $dead ? S::DEAD : S::FAILED, 'attempts' => $attempts, 'last_error' => mb_substr($e->getMessage(), 0, 2000),
                    'available_at' => now()->addSeconds(min(3600, 15 * (2 ** ($attempts - 1))))])->save();
                $stats[$dead ? 'dead' : 'failed']++;
            }
        }

        return $stats;
    }

    /** Requeue dead events (operator action). */
    public function retryDead(array $ids): int
    {
        return DB::table('outbox_events')->whereIn('id', $ids)->where('status', S::DEAD->value)
            ->update(['status' => S::PENDING->value, 'attempts' => 0, 'available_at' => now(), 'updated_at' => now()]);
    }
}
