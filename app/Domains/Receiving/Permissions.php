<?php

declare(strict_types=1);

namespace App\Domains\Receiving;

use App\Domains\IdentityAccess\Enums\PermissionScopeKind as K;
use App\Domains\IdentityAccess\Enums\PermissionSensitivity as S;
use App\Domains\IdentityAccess\Permissions\PermissionDefinition as P;
use App\Domains\IdentityAccess\Permissions\ProvidesPermissions;

final class Permissions implements ProvidesPermissions
{
    public static function module(): string
    {
        return 'receiving';
    }

    public static function moduleLabel(): string
    {
        return 'Receiving & QC';
    }

    public static function definitions(): array
    {
        return [
            ...P::crud('receiving.goods_receipt', 'goods receipts', ['read', 'create', 'update', 'submit', 'approve', 'post', 'cancel'], K::WAREHOUSE, [
                'approve' => ['sensitivity' => S::ELEVATED], 'post' => ['sensitivity' => S::ELEVATED],
            ]),
            P::warehouse('receiving.goods_receipt.direct_receipt', 'Receive without a purchase order', '', S::ELEVATED, ['receiving.goods_receipt.create']),
            P::warehouse('receiving.over_receipt.override', 'Override over-receipt tolerance', '', S::CRITICAL, ['receiving.goods_receipt.read']),
            P::warehouse('receiving.qc.inspect', 'Record quality inspections', '', S::NORMAL, ['receiving.goods_receipt.read']),
            P::warehouse('receiving.qc.release', 'Release stock from QC', '', S::ELEVATED, ['receiving.qc.inspect']),
            P::warehouse('receiving.putaway.read', 'View put-away tasks'),
            P::warehouse('receiving.putaway.assign', 'Assign put-away tasks', '', S::NORMAL, ['receiving.putaway.read']),
            P::warehouse('receiving.putaway.post', 'Post put-away moves', '', S::NORMAL, ['receiving.putaway.read']),
        ];
    }
}
