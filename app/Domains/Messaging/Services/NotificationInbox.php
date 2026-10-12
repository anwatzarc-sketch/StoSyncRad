<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Services;

use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Messaging\Enums\NotificationChannel;
use App\Domains\Messaging\Enums\NotificationStatus as S;
use App\Domains\Messaging\Models\Notification;
use App\Domains\Messaging\Models\NotificationPreference;
use App\Domains\Platform\Config\Keys\MessagingKeys;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Illuminate\Contracts\Pagination\CursorPaginator;

/** A person's own notifications and channel preferences. */
final class NotificationInbox
{
    public function list(UserAccount $account, ?S $status = null, int $perPage = 30): CursorPaginator
    {
        return Notification::query()->where('user_account_id', $account->id)
            ->when($status !== null, fn ($q) => $q->where('status', $status->value), fn ($q) => $q->where('status', '<>', S::ARCHIVED->value))
            ->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate(min(100, max(1, $perPage)));
    }

    public function unreadCount(UserAccount $account): int
    {
        return Notification::query()->where('user_account_id', $account->id)->where('status', S::UNREAD->value)->count();
    }

    /** @param  array<int, string>|null  $ids  null = all */
    public function mark(UserAccount $account, S $status, ?array $ids = null): int
    {
        return Notification::query()->where('user_account_id', $account->id)
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->update(['status' => $status->value, 'read_at' => $status === S::UNREAD ? null : now(), 'updated_at' => now()]);
    }

    /** @return array<int, array{event_type: string, channels: array<int, string>, is_muted: bool}> */
    public function preferences(UserAccount $account): array
    {
        $saved = NotificationPreference::query()->where('user_account_id', $account->id)->get()->keyBy('event_type');

        return array_map(fn (string $e) => ['event_type' => $e, 'channels' => $saved[$e]->channels ?? [], 'is_muted' => (bool) ($saved[$e]->is_muted ?? false)],
            MessagingKeys::EVENTS);
    }

    /** @param  array<int, string>  $channels */
    public function setPreference(UserAccount $account, string $eventType, array $channels, bool $muted): NotificationPreference
    {
        if (! in_array($eventType, MessagingKeys::EVENTS, true)) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, "Unknown notification event `$eventType`.", ['field' => 'event_type']);
        }
        foreach ($channels as $c) {
            NotificationChannel::tryFrom($c) ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED,
                "`$c` is not a notification channel. Allowed: ".implode(', ', NotificationChannel::values()).'.', ['field' => 'channels']);
        }

        return NotificationPreference::query()->updateOrCreate(['user_account_id' => $account->id, 'event_type' => $eventType],
            ['channels' => array_values(array_unique($channels)), 'is_muted' => $muted]);
    }
}
