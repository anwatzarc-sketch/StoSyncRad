<?php

declare(strict_types=1);

namespace App\Domains\Stocktaking;

use App\Domains\IdentityAccess\Enums\PermissionScopeKind as K;
use App\Domains\IdentityAccess\Enums\PermissionSensitivity as S;
use App\Domains\IdentityAccess\Permissions\PermissionDefinition as P;
use App\Domains\IdentityAccess\Permissions\ProvidesPermissions;

final class Permissions implements ProvidesPermissions
{
    public static function module(): string
    {
        return 'stocktaking';
    }

    public static function moduleLabel(): string
    {
        return 'Stocktaking & Adjustments';
    }

    public static function definitions(): array
    {
        return [
            ...P::crud('stocktaking.stocktake', 'stocktakes', ['read', 'create', 'schedule', 'update', 'cancel', 'count', 'recount', 'review', 'approve', 'post'], K::WAREHOUSE, [
                'approve' => ['sensitivity' => S::ELEVATED], 'post' => ['sensitivity' => S::ELEVATED],
            ]),
            P::warehouse('stocktaking.stocktake.view_book_qty', 'See book quantities during blind counts', '', S::ELEVATED, ['stocktaking.stocktake.read']),
            ...P::crud('stocktaking.adjustment', 'stock adjustments', ['read', 'create', 'update', 'submit', 'approve', 'post', 'cancel'], K::WAREHOUSE, [
                'approve' => ['sensitivity' => S::ELEVATED], 'post' => ['sensitivity' => S::ELEVATED],
            ]),
        ];
    }
}
