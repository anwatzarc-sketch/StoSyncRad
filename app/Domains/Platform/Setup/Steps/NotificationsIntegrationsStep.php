<?php

declare(strict_types=1);

namespace App\Domains\Platform\Setup\Steps;

use App\Domains\Platform\Enums\PlatformSetupStep;

/** Step 14 (optional) — notification channels, integrations and feature flags. */
final class NotificationsIntegrationsStep extends ConfigOnlyStep
{
    public function step(): PlatformSetupStep
    {
        return PlatformSetupStep::NOTIFICATIONS_INTEGRATIONS;
    }

    protected function prefixes(): array
    {
        return ['messaging.', 'integration.', 'feature.', 'api.'];
    }
}
