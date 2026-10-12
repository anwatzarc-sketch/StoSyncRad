<?php

declare(strict_types=1);

namespace App\Domains\Organization;

use App\Domains\IdentityAccess\Enums\PermissionScopeKind as K;
use App\Domains\IdentityAccess\Enums\PermissionSensitivity as S;
use App\Domains\IdentityAccess\Permissions\PermissionDefinition as P;
use App\Domains\IdentityAccess\Permissions\ProvidesPermissions;

final class Permissions implements ProvidesPermissions
{
    public static function module(): string
    {
        return 'organization';
    }

    public static function moduleLabel(): string
    {
        return 'Organization';
    }

    public static function definitions(): array
    {
        return [
            ...P::crud('organization.branch', 'branches', ['read', 'create', 'update', 'close'], K::GLOBAL, ['close' => ['sensitivity' => S::ELEVATED]]),
            P::warehouse('organization.warehouse.read', 'View warehouses'),
            P::global('organization.warehouse.create', 'Create warehouses', '', S::ELEVATED, ['organization.warehouse.read']),
            P::warehouse('organization.warehouse.update', 'Update warehouses', '', S::ELEVATED, ['organization.warehouse.read']),
            P::warehouse('organization.warehouse.close', 'Close warehouses', '', S::ELEVATED, ['organization.warehouse.read']),
            ...P::crud('organization.storage_location', 'storage locations', ['read', 'create', 'update', 'block', 'archive'], K::WAREHOUSE),
            P::warehouse('organization.putaway_rule.read', 'View put-away rules'),
            P::warehouse('organization.putaway_rule.manage', 'Manage put-away rules', '', S::NORMAL, ['organization.putaway_rule.read']),
        ];
    }
}
