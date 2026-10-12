<?php

declare(strict_types=1);

namespace App\Domains\Platform\Config\Keys;

use App\Domains\Platform\Config\DefinesConfigKeys;
use App\Domains\Platform\Config\ProvidesConfigKeys;

final class SecurityKeys implements ProvidesConfigKeys
{
    use DefinesConfigKeys;

    public static function definitions(): array
    {
        return [
            self::int('security.session.lifetime_minutes', 480, 'Session lifetime', 'Maximum minutes a session remains valid.', 'G', 15, 1440, true),
            self::int('security.session.idle_timeout_minutes', 30, 'Idle timeout', 'Minutes of inactivity before re-authentication is required.', 'G', 5, 480),
            self::int('security.session.absolute_timeout_hours', 12, 'Absolute timeout', 'Hours after login when the session ends regardless of activity.', 'G', 1, 72),
            self::int('security.session.concurrent_limit', 3, 'Concurrent sessions', 'Maximum simultaneous sessions per account (0 = unlimited).', 'G', 0, 10),
            self::bool('security.session.invalidate_on_role_change', true, 'Invalidate sessions on role change', 'Log the account out of other sessions when its roles or scopes change.'),
            self::list('security.step_up.permissions', [], 'Additional step-up permissions', 'Permission code patterns (wildcards allowed) requiring recent re-verification in addition to all critical permissions.'),
            self::int('security.step_up.validity_minutes', 10, 'Step-up validity', 'Minutes a step-up verification remains valid.', 'G', 1, 60),
            self::list('security.ip_allowlist', [], 'IP allow-list', 'CIDR ranges allowed to sign in (empty = no restriction).'),
            self::int('security.api_token.max_lifetime_days', 90, 'API token lifetime', 'Maximum lifetime of API tokens in days.', 'G', 1, 730),
            self::bool('security.audit.capture_ip', true, 'Capture IP in audit', 'Record client IP addresses in audit events.'),
            self::bool('security.audit.capture_user_agent', true, 'Capture user agent in audit', 'Record client user agents in audit events.'),
        ];
    }
}
