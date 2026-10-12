<?php

declare(strict_types=1);

namespace App\Domains\Billing;

use App\Domains\IdentityAccess\Enums\PermissionScopeKind as K;
use App\Domains\IdentityAccess\Enums\PermissionSensitivity as S;
use App\Domains\IdentityAccess\Permissions\PermissionDefinition as P;
use App\Domains\IdentityAccess\Permissions\ProvidesPermissions;

/** Invoices, review of customer payment evidence, and payments recorded by finance. Only these permissions change balances. */
final class Permissions implements ProvidesPermissions
{
    public const FEATURE = 'feature.customer_portal';

    public static function module(): string
    {
        return 'billing';
    }

    public static function moduleLabel(): string
    {
        return 'Billing & payments';
    }

    public static function definitions(): array
    {
        return [
            ...P::crud('billing.invoice', 'invoices', ['read', 'create', 'issue', 'void'], K::GLOBAL, [
                'issue' => ['sensitivity' => S::ELEVATED], 'void' => ['sensitivity' => S::CRITICAL],
            ]),
            ...P::crud('billing.payment_confirmation', 'customer payment confirmations', ['read', 'review'], K::GLOBAL, [
                'review' => ['sensitivity' => S::ELEVATED, 'label' => 'Request information on or reject payment confirmations'],
            ]),
            ...P::crud('billing.tax', 'tax rates', ['read', 'manage'], K::GLOBAL),
            ...P::crud('billing.credit', 'customer credit', ['manage', 'override'], K::GLOBAL, [
                'manage' => ['label' => 'Set customer credit limits', 'sensitivity' => S::ELEVATED, 'implies' => []],
                'override' => ['label' => 'Confirm orders above the credit limit (with a reason)', 'sensitivity' => S::ELEVATED, 'implies' => []],
            ]),
            ...P::crud('billing.payment', 'payments', ['read', 'record', 'allocate', 'void'], K::GLOBAL, [
                'record' => ['sensitivity' => S::ELEVATED, 'label' => 'Record verified payments'],
                'allocate' => ['sensitivity' => S::ELEVATED, 'label' => 'Allocate payments to invoices'],
                'void' => ['sensitivity' => S::CRITICAL],
            ]),
        ];
    }
}
