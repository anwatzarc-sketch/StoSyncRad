<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Models;

use App\Domains\IdentityAccess\Enums\PermissionScopeKind;
use App\Domains\IdentityAccess\Enums\PermissionSensitivity;
use App\Support\Eloquent\BaseModel;

/**
 * Synced permission row (Chapter 04 §4.4). Source of truth is the code definition.
 *
 * @property string $code
 * @property string $module
 * @property string $resource
 * @property string $action
 * @property string $label
 * @property string $description
 * @property PermissionScopeKind $scope_kind
 * @property PermissionSensitivity $sensitivity
 * @property bool $is_virtual
 * @property bool $is_deprecated
 * @property string|null $feature_flag
 */
final class Permission extends BaseModel
{
    protected $table = 'permissions';

    protected $fillable = ['code', 'module', 'resource', 'action', 'label', 'description', 'scope_kind', 'sensitivity', 'is_virtual', 'is_deprecated', 'feature_flag'];

    protected function casts(): array
    {
        return [
            'scope_kind' => PermissionScopeKind::class,
            'sensitivity' => PermissionSensitivity::class,
            'is_virtual' => 'boolean',
            'is_deprecated' => 'boolean',
        ];
    }
}
