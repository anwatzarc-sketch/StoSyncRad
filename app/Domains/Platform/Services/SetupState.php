<?php

declare(strict_types=1);

namespace App\Domains\Platform\Services;

use App\Domains\Platform\Enums\PlatformSetupState;
use App\Domains\Platform\Models\PlatformSetup;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

/**
 * Cheap, memoized access to the single platform_setup row (Chapter 02 §2.2).
 * Safe before migrations: a missing table reads as NOT_STARTED.
 */
final class SetupState
{
    private ?PlatformSetup $row = null;

    private bool $loaded = false;

    public function current(): ?PlatformSetup
    {
        if ($this->loaded) {
            return $this->row;
        }
        $this->loaded = true;

        try {
            if (! Schema::hasTable('platform_setup')) {
                return $this->row = null;
            }

            return $this->row = PlatformSetup::query()->first();
        } catch (QueryException) {
            return $this->row = null;
        }
    }

    public function state(): PlatformSetupState
    {
        return $this->current()?->state ?? PlatformSetupState::NOT_STARTED;
    }

    public function isComplete(): bool
    {
        return in_array($this->state(), [PlatformSetupState::COMPLETED, PlatformSetupState::REOPENED], true);
    }

    public function isReopened(): bool
    {
        return $this->state() === PlatformSetupState::REOPENED;
    }

    public function refresh(): void
    {
        $this->loaded = false;
        $this->row = null;
    }
}
