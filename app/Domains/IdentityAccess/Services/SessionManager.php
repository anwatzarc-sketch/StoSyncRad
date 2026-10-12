<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Services;

use App\Domains\IdentityAccess\Enums\SecurityEventType;
use App\Domains\IdentityAccess\Models\SecurityEvent;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Platform\Config\PlatformConfig;
use Illuminate\Support\Facades\DB;

/**
 * Session lifecycle helpers (Chapter 04 §4.2): invalidate on privilege change / deactivation,
 * enforce the concurrent-session limit, revoke API tokens on deactivation.
 */
final class SessionManager
{
    public function __construct(private readonly PlatformConfig $config) {}

    public function afterPrivilegeChange(UserAccount $account): void
    {
        if ($this->config->bool('security.session.invalidate_on_role_change')) {
            $this->revokeAll($account, keepCurrent: true);
        }
    }

    public function revokeAll(UserAccount $account, bool $keepCurrent = false, bool $includeTokens = false): int
    {
        $query = DB::table('sessions')->where('user_id', $account->id);
        if ($keepCurrent && app()->bound('session') && request()->hasSession()) {
            $query->where('id', '<>', request()->session()->getId());
        }
        $count = $query->delete();
        if ($includeTokens) {
            $account->tokens()->delete();
        }
        if ($count > 0 || $includeTokens) {
            SecurityEvent::log(SecurityEventType::SESSION_REVOKED, $account->id, null, ['sessions' => $count, 'tokens' => $includeTokens]);
        }

        return $count;
    }

    /** Drop the oldest sessions beyond security.session.concurrent_limit (0 = unlimited). */
    public function enforceConcurrentLimit(UserAccount $account, string $currentSessionId): void
    {
        $limit = $this->config->int('security.session.concurrent_limit');
        if ($limit <= 0) {
            return;
        }
        $ids = DB::table('sessions')->where('user_id', $account->id)->where('id', '<>', $currentSessionId)
            ->orderByDesc('last_activity')->pluck('id');
        $excess = $ids->slice($limit - 1);
        if ($excess->isNotEmpty()) {
            DB::table('sessions')->whereIn('id', $excess->all())->delete();
        }
    }
}
