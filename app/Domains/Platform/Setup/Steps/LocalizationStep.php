<?php

declare(strict_types=1);

namespace App\Domains\Platform\Setup\Steps;

use App\Domains\Platform\Enums\PlatformSetupStep;

/** Step 5 — locale, timezone, calendar and formats. */
final class LocalizationStep extends ConfigOnlyStep
{
    public function step(): PlatformSetupStep
    {
        return PlatformSetupStep::LOCALIZATION;
    }

    protected function prefixes(): array
    {
        return ['locale.', 'platform.timezone_display_mode'];
    }
}
