<?php

declare(strict_types=1);

namespace App\Domains\Platform\Models;

use App\Domains\Platform\Enums\PlatformSetupState;
use App\Domains\Platform\Enums\PlatformSetupStep;
use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;

/**
 * Single-row setup state machine (Chapter 02 §2.2).
 *
 * @property string $id
 * @property PlatformSetupState $state
 * @property PlatformSetupStep|null $current_step
 * @property \ArrayObject<string, array{completed_at: string, actor: ?string, checksum: string}> $completed_steps
 * @property string|null $bootstrap_token_hash
 * @property \Carbon\CarbonImmutable|null $bootstrap_token_expires_at
 * @property \Carbon\CarbonImmutable|null $completed_at
 * @property string|null $completed_by_account_id
 * @property \Carbon\CarbonImmutable|null $locked_at
 * @property \Carbon\CarbonImmutable|null $reopened_at
 * @property string|null $config_bundle_checksum
 * @property string|null $deployment_id
 */
final class PlatformSetup extends BaseModel
{
    protected $table = 'platform_setup';

    protected $fillable = [
        'state', 'current_step', 'completed_steps', 'bootstrap_token_hash', 'bootstrap_token_expires_at',
        'completed_at', 'completed_by_account_id', 'locked_at', 'reopened_at', 'config_bundle_checksum', 'deployment_id',
    ];

    protected function casts(): array
    {
        return [
            'state' => PlatformSetupState::class,
            'current_step' => PlatformSetupStep::class,
            'completed_steps' => AsArrayObject::class,
            'bootstrap_token_expires_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'locked_at' => 'immutable_datetime',
            'reopened_at' => 'immutable_datetime',
        ];
    }

    public function isStepCompleted(PlatformSetupStep $step): bool
    {
        return isset($this->completed_steps[$step->value]);
    }
}
