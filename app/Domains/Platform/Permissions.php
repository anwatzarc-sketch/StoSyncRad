<?php

declare(strict_types=1);

namespace App\Domains\Platform;

use App\Domains\IdentityAccess\Enums\PermissionSensitivity as S;
use App\Domains\IdentityAccess\Permissions\PermissionDefinition as P;
use App\Domains\IdentityAccess\Permissions\ProvidesPermissions;

final class Permissions implements ProvidesPermissions
{
    public const CONFIG_GROUPS = ['locale', 'identity', 'security', 'catalog', 'inventory', 'stock', 'procurement', 'receiving',
        'transfers', 'fulfillment', 'stocktaking', 'approval', 'reporting', 'messaging', 'integration', 'feature', 'api', 'db', 'exchange', 'platform'];

    public static function module(): string
    {
        return 'platform';
    }

    public static function moduleLabel(): string
    {
        return 'Platform';
    }

    public static function definitions(): array
    {
        $defs = [
            P::global('platform.platform.manage', 'Platform administration', 'Access Administration → Platform.', S::CRITICAL),
            P::global('platform.setup.reopen', 'Reopen Platform Setup', 'Reopen the setup wizard (maintenance mode required).', S::CRITICAL, ['platform.platform.manage']),
            P::global('platform.company.read', 'View company profile'),
            P::global('platform.company.manage', 'Manage company profile', '', S::ELEVATED, ['platform.company.read']),
            P::global('platform.brand.read', 'View brand kits'),
            P::global('platform.brand.manage', 'Manage and activate brand kits', '', S::ELEVATED, ['platform.brand.read']),
            P::global('platform.config.read', 'View configuration'),
            P::global('platform.lookup.read', 'View lookups'),
            P::global('platform.lookup.manage', 'Manage lookups', '', S::NORMAL, ['platform.lookup.read']),
            P::global('platform.enum_label.manage', 'Manage enum labels'),
            P::global('platform.translation.manage', 'Manage translations'),
            P::global('platform.template.read', 'View templates'),
            P::global('platform.template.manage', 'Manage templates', '', S::ELEVATED, ['platform.template.read']),
            P::global('platform.numbering.manage', 'Manage document numbering', '', S::ELEVATED),
            P::global('platform.bundle.export', 'Export configuration bundles', '', S::CRITICAL),
            P::global('platform.bundle.import', 'Import configuration bundles', '', S::CRITICAL),
            P::global('platform.health.read', 'View system health'),
            P::global('platform.inventory_locations.manage', 'Manage virtual inventory locations', '', S::CRITICAL),
            P::global('platform.meta.read', 'Read platform metadata (enums, schemas, permissions)', ''),
        ];

        foreach (self::CONFIG_GROUPS as $group) {
            $defs[] = P::global("platform.config.{$group}_manage", "Manage {$group} configuration", "Change configuration keys in the `{$group}` group.", S::ELEVATED, ['platform.config.read']);
        }

        return $defs;
    }
}
