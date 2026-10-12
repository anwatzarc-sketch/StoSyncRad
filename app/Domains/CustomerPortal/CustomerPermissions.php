<?php

declare(strict_types=1);

namespace App\Domains\CustomerPortal;

use App\Domains\IdentityAccess\Enums\PermissionScopeKind as K;
use App\Domains\IdentityAccess\Enums\PermissionSensitivity as S;
use App\Domains\IdentityAccess\Permissions\PermissionDefinition as P;
use App\Domains\IdentityAccess\Permissions\ProvidesPermissions;

/** What a customer member may do in the Customer Portal. Granted only through the system "Customer portal member" role, always limited to the records of the member's own customer (never staff data). */
final class CustomerPermissions implements ProvidesPermissions
{
    public const FEATURE = 'feature.customer_portal';

    public static function module(): string
    {
        return 'customer';
    }

    public static function moduleLabel(): string
    {
        return 'Customer portal members';
    }

    public static function definitions(): array
    {
        return [
            ...P::crud('customer.catalog', 'the product catalog', ['browse'], K::GLOBAL, ['browse' => ['implies' => []]], self::FEATURE),
            ...P::crud('customer.quotation', 'own quotations', ['read', 'request', 'respond'], K::GLOBAL, [
                'respond' => ['label' => 'Accept, decline or revise own quotations'],
            ], self::FEATURE),
            ...P::crud('customer.order', 'own orders', ['read', 'place', 'cancel'], K::GLOBAL, [
                'place' => ['implies' => ['customer.order.read', 'customer.catalog.browse']],
            ], self::FEATURE),
            ...P::crud('customer.delivery', 'own deliveries', ['confirm'], K::GLOBAL, ['confirm' => ['implies' => ['customer.order.read']]], self::FEATURE),
            ...P::crud('customer.invoice', 'own invoices', ['read'], K::GLOBAL, [], self::FEATURE),
            ...P::crud('customer.payment', 'own payment confirmations', ['read', 'submit'], K::GLOBAL, [
                'submit' => ['label' => 'Submit payment confirmations with evidence', 'implies' => ['customer.payment.read', 'customer.invoice.read']],
            ], self::FEATURE),
            ...P::crud('customer.account', 'own portal account', ['manage'], K::GLOBAL, ['manage' => ['implies' => []]], self::FEATURE),
            ...P::crud('customer.member', 'colleagues in the portal', ['read', 'manage'], K::GLOBAL, [
                'manage' => ['label' => 'Invite colleagues, change their role and remove them (customer administrators)'],
            ], self::FEATURE),
        ];
    }
}
