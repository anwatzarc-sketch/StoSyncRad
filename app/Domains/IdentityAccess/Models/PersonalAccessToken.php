<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

/**
 * Sanctum token with a UUID v7 primary key (Chapter 04 §4.3).
 * Abilities are a subset of the account's permission codes and are re-checked live.
 */
final class PersonalAccessToken extends SanctumToken
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }
}
