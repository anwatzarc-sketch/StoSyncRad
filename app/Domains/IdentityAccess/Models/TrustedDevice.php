<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Models;

use App\Support\Eloquent\BaseModel;

/**
 * Device remembered after a successful MFA challenge (identity.mfa.trusted_device_days).
 *
 * @property string $user_account_id
 * @property string $token_hash
 * @property string|null $label
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property \Carbon\CarbonImmutable $expires_at
 * @property \Carbon\CarbonImmutable|null $last_seen_at
 */
final class TrustedDevice extends BaseModel
{
    protected $table = 'trusted_devices';

    protected $hidden = ['token_hash'];

    protected $fillable = ['user_account_id', 'token_hash', 'label', 'ip_address', 'user_agent', 'expires_at', 'last_seen_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'last_seen_at' => 'immutable_datetime'];
    }
}
