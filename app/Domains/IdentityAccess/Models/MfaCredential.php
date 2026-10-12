<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Models;

use App\Support\Eloquent\BaseModel;

/**
 * TOTP credential + hashed recovery codes for one account (Chapter 04 §4.2).
 *
 * @property string $user_account_id
 * @property string $secret  encrypted
 * @property array<int, string> $recovery_code_hashes
 * @property \Carbon\CarbonImmutable|null $confirmed_at
 * @property \Carbon\CarbonImmutable|null $last_used_at
 */
final class MfaCredential extends BaseModel
{
    protected $table = 'mfa_credentials';

    protected $hidden = ['secret', 'recovery_code_hashes'];

    protected $fillable = ['user_account_id', 'secret', 'recovery_code_hashes', 'confirmed_at', 'last_used_at', 'last_used_counter'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'recovery_code_hashes' => 'array',
            'confirmed_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
            'last_used_counter' => 'integer',
        ];
    }
}
