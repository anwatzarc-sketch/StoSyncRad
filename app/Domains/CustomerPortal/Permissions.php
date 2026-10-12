<?php

declare(strict_types=1);

namespace App\Domains\CustomerPortal;

use App\Domains\IdentityAccess\Enums\PermissionScopeKind as K;
use App\Domains\IdentityAccess\Enums\PermissionSensitivity as S;
use App\Domains\IdentityAccess\Permissions\PermissionDefinition as P;
use App\Domains\IdentityAccess\Permissions\ProvidesPermissions;

/** Staff administration of the Customer Portal: registrations, invitations and delivery discrepancies. */
final class Permissions implements ProvidesPermissions
{
    public const FEATURE = 'feature.customer_portal';

    public static function module(): string
    {
        return 'portal';
    }

    public static function moduleLabel(): string
    {
        return 'Customer portal administration';
    }

    public static function definitions(): array
    {
        return [
            ...P::crud('portal.registration', 'customer registrations', ['read', 'approve', 'reject'], K::GLOBAL, [
                'approve' => ['sensitivity' => S::ELEVATED, 'label' => 'Approve customer registrations and link them to a customer'],
            ], self::FEATURE),
            ...P::crud('portal.invitation', 'customer invitations', ['read', 'create', 'revoke'], K::GLOBAL, [], self::FEATURE),
            ...P::crud('portal.acknowledgement', 'delivery acknowledgements', ['read', 'resolve'], K::GLOBAL, [], self::FEATURE),
        ];
    }
}
