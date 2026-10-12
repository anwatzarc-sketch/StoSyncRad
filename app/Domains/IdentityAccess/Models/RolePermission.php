<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Models;

use App\Domains\IdentityAccess\Enums\PermissionGrantSource;
use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $role_id
 * @property string $permission_id
 * @property string $permission_code
 * @property PermissionGrantSource $granted_via
 * @property array<string,mixed>|null $constraints  e.g. {"max_value": "50000", "max_quantity": "1000"}
 */
final class RolePermission extends BaseModel
{
    protected $table = 'role_permissions';

    protected $fillable = ['role_id', 'permission_id', 'permission_code', 'granted_via', 'constraints'];

    protected function casts(): array
    {
        return ['granted_via' => PermissionGrantSource::class, 'constraints' => 'array'];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class);
    }
}
