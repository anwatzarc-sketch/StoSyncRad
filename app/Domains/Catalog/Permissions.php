<?php

declare(strict_types=1);

namespace App\Domains\Catalog;

use App\Domains\IdentityAccess\Enums\PermissionScopeKind as K;
use App\Domains\IdentityAccess\Enums\PermissionSensitivity as S;
use App\Domains\IdentityAccess\Permissions\PermissionDefinition as P;
use App\Domains\IdentityAccess\Permissions\ProvidesPermissions;

final class Permissions implements ProvidesPermissions
{
    public static function module(): string
    {
        return 'catalog';
    }

    public static function moduleLabel(): string
    {
        return 'Product Catalog';
    }

    public static function definitions(): array
    {
        return [
            ...P::crud('catalog.item', 'items', ['read', 'create', 'update', 'activate', 'deactivate', 'archive']),
            P::global('catalog.item.convert_tracking', 'Convert item tracking mode', '', S::CRITICAL, ['catalog.item.update']),
            P::global('catalog.item.view_cost', 'View item cost fields', 'Exposes cost/valuation fields on items and lists.', S::ELEVATED, [], true),
            P::global('catalog.item.manage_cost', 'Manage item cost settings', '', S::CRITICAL, ['catalog.item.view_cost', 'catalog.item.update']),
            P::global('catalog.variant.read', 'View variants'),
            P::global('catalog.variant.manage', 'Manage variants', '', S::NORMAL, ['catalog.variant.read']),
            P::global('catalog.barcode.read', 'View barcodes'),
            P::global('catalog.barcode.manage', 'Manage barcodes', '', S::NORMAL, ['catalog.barcode.read']),
            P::global('catalog.label.print', 'Print item labels'),
            P::global('catalog.packaging.manage', 'Manage packagings'),
            P::global('catalog.uom.read', 'View units of measure'),
            P::global('catalog.uom.manage', 'Manage units of measure', '', S::ELEVATED, ['catalog.uom.read']),
            ...P::crud('catalog.category', 'categories', ['read', 'create', 'update', 'move', 'archive'], K::GLOBAL, ['move' => ['sensitivity' => S::ELEVATED]]),
            P::global('catalog.tag.manage', 'Manage product tags'),
            P::global('catalog.vendor_pricelist.read', 'View vendor pricelists'),
            P::global('catalog.vendor_pricelist.manage', 'Manage vendor pricelists', '', S::NORMAL, ['catalog.vendor_pricelist.read']),
            P::warehouse('catalog.reorder_rule.read', 'View reorder rules'),
            P::warehouse('catalog.reorder_rule.manage', 'Manage reorder rules', '', S::NORMAL, ['catalog.reorder_rule.read']),
            P::global('catalog.qc_template.read', 'View QC checklist templates'),
            P::global('catalog.qc_template.manage', 'Manage QC checklist templates', '', S::NORMAL, ['catalog.qc_template.read']),
            P::global('catalog.kit.manage', 'Manage kits', '', S::NORMAL, [], false, 'feature.kits'),
        ];
    }
}
