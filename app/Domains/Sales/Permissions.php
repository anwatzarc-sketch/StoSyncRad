<?php

declare(strict_types=1);

namespace App\Domains\Sales;

use App\Domains\IdentityAccess\Enums\PermissionScopeKind as K;
use App\Domains\IdentityAccess\Enums\PermissionSensitivity as S;
use App\Domains\IdentityAccess\Permissions\PermissionDefinition as P;
use App\Domains\IdentityAccess\Permissions\ProvidesPermissions;

/** Staff side of customer quotations. */
final class Permissions implements ProvidesPermissions
{
    public const FEATURE = 'feature.customer_portal';

    public static function module(): string
    {
        return 'sales';
    }

    public static function moduleLabel(): string
    {
        return 'Sales quotations';
    }

    public static function definitions(): array
    {
        return [
            ...P::crud('sales.quotation', 'quotations', ['read', 'price', 'cancel'], K::GLOBAL, [
                'price' => ['sensitivity' => S::ELEVATED, 'label' => 'Price and send quotations'],
            ], self::FEATURE),
        ];
    }
}
