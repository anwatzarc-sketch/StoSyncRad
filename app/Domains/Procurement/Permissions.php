<?php

declare(strict_types=1);

namespace App\Domains\Procurement;

use App\Domains\IdentityAccess\Enums\PermissionScopeKind as K;
use App\Domains\IdentityAccess\Enums\PermissionSensitivity as S;
use App\Domains\IdentityAccess\Permissions\PermissionDefinition as P;
use App\Domains\IdentityAccess\Permissions\ProvidesPermissions;

final class Permissions implements ProvidesPermissions
{
    public static function module(): string
    {
        return 'procurement';
    }

    public static function moduleLabel(): string
    {
        return 'Procurement';
    }

    public static function definitions(): array
    {
        return [
            ...P::crud('procurement.purchase_request', 'purchase requests', ['read', 'create', 'update', 'submit', 'approve', 'reject', 'cancel'], K::WAREHOUSE, [
                'approve' => ['sensitivity' => S::ELEVATED], 'reject' => ['sensitivity' => S::ELEVATED],
            ]),
            P::own('procurement.purchase_request.read_own', 'View own purchase requests'),
            ...P::crud('procurement.purchase_order', 'purchase orders', ['read', 'create', 'update', 'submit', 'approve', 'reject', 'send', 'revise', 'close', 'cancel'], K::WAREHOUSE, [
                'approve' => ['sensitivity' => S::ELEVATED], 'reject' => ['sensitivity' => S::ELEVATED], 'revise' => ['sensitivity' => S::ELEVATED], 'cancel' => ['sensitivity' => S::ELEVATED],
            ]),
            P::warehouse('procurement.purchase_order.view_cost', 'View purchase order costs', '', S::ELEVATED, ['procurement.purchase_order.read']),
            ...P::crud('procurement.supplier_return', 'supplier returns', ['read', 'create', 'update', 'submit', 'approve', 'post', 'cancel'], K::WAREHOUSE, [
                'approve' => ['sensitivity' => S::ELEVATED], 'post' => ['sensitivity' => S::ELEVATED],
            ]),
            P::warehouse('procurement.asn.read', 'View advance shipment notices', '', S::NORMAL, [], 'feature.asn'),
            P::warehouse('procurement.asn.create', 'Create advance shipment notices', '', S::NORMAL, ['procurement.asn.read'], 'feature.asn'),
        ];
    }
}
