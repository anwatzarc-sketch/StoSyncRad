<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Models;

use App\Domains\IdentityAccess\Enums\AccountStatus;
use App\Domains\IdentityAccess\Enums\AccountType;
use App\Domains\IdentityAccess\Enums\MfaStatus;
use App\Domains\IdentityAccess\Schemas\UserAccountSchema;
use Illuminate\Auth\Authenticatable;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

/**
 * Login identity owned by a person (Chapter 04 §4.1). The authenticated actor everywhere.
 *
 * @property string $id
 * @property string $person_id
 * @property string $username
 * @property string $email
 * @property string|null $password_hash
 * @property AccountType $account_type
 * @property AccountStatus $account_status
 * @property bool $is_super_admin
 * @property MfaStatus $mfa_status
 * @property \Carbon\CarbonImmutable|null $password_changed_at
 * @property bool $must_change_password
 * @property \Carbon\CarbonImmutable|null $last_login_at
 * @property string|null $last_login_ip
 * @property int $failed_login_count
 * @property \Carbon\CarbonImmutable|null $locked_until
 * @property \Carbon\CarbonImmutable|null $deactivated_at
 * @property string|null $deactivated_by
 * @property-read Person|null $person
 */
final class UserAccount extends Model implements AuthenticatableContract, CanResetPasswordContract
{
    use Authenticatable;
    use CanResetPassword;
    use HasApiTokens;
    use HasUuids;
    use Notifiable;

    protected $table = 'user_accounts';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $hidden = ['password_hash', 'remember_token'];

    protected $fillable = [
        'person_id', 'username', 'email', 'password_hash', 'account_type', 'account_status', 'is_super_admin', 'mfa_status',
        'password_changed_at', 'must_change_password', 'last_login_at', 'last_login_ip', 'failed_login_count', 'locked_until',
        'deactivated_at', 'deactivated_by', 'created_by', 'updated_by',
    ];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    protected function casts(): array
    {
        return [
            'account_type' => AccountType::class,
            'account_status' => AccountStatus::class,
            'mfa_status' => MfaStatus::class,
            'is_super_admin' => 'boolean',
            'must_change_password' => 'boolean',
            'failed_login_count' => 'integer',
            'password_changed_at' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
            'locked_until' => 'immutable_datetime',
            'deactivated_at' => 'immutable_datetime',
        ];
    }

    public static function schema(): string
    {
        return UserAccountSchema::class;
    }

    // ---- Auth contract -------------------------------------------------------------------

    public function getAuthPassword(): string
    {
        return (string) $this->password_hash;
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function getEmailForPasswordReset(): string
    {
        return (string) $this->email;
    }

    // ---- Relations -----------------------------------------------------------------------

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }

    public function mfaCredential(): HasOne
    {
        return $this->hasOne(MfaCredential::class);
    }

    public function trustedDevices(): HasMany
    {
        return $this->hasMany(TrustedDevice::class);
    }

    // ---- State helpers -------------------------------------------------------------------

    public function isActive(): bool
    {
        return $this->account_status === AccountStatus::ACTIVE
            && ($this->locked_until === null || $this->locked_until->isPast());
    }

    public function isHuman(): bool
    {
        return $this->account_type === AccountType::HUMAN;
    }

    public function displayName(): string
    {
        return $this->person?->full_name ?? $this->username;
    }
}
