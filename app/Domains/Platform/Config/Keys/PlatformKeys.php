<?php

declare(strict_types=1);

namespace App\Domains\Platform\Config\Keys;

use App\Domains\Platform\Config\DefinesConfigKeys;
use App\Domains\Platform\Config\ProvidesConfigKeys;
use App\Domains\Platform\Enums\TimezoneDisplayMode;

final class PlatformKeys implements ProvidesConfigKeys
{
    use DefinesConfigKeys;

    public static function definitions(): array
    {
        return [
            self::str('platform.deployment_label', '', 'Deployment label', 'Free-text label identifying this deployment in health reports and bundles.', 'G', ['max_length' => 120]),
            self::enum('platform.timezone_display_mode', TimezoneDisplayMode::class, 'company', 'Timezone display mode', 'Display dates in the company timezone, the branch timezone, or the viewer\'s timezone.'),
            self::int('platform.health.scheduler_heartbeat_minutes', 10, 'Scheduler heartbeat window', 'Readiness fails when the scheduler has not run within this many minutes.', 'G', 1, 1440),
        ];
    }
}
