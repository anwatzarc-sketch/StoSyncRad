<?php

declare(strict_types=1);

namespace App\Domains\Inventory;

use App\Domains\IdentityAccess\Enums\PermissionSensitivity as S;
use App\Domains\IdentityAccess\Permissions\PermissionDefinition as P;
use App\Domains\IdentityAccess\Permissions\ProvidesPermissions;

final class Permissions implements ProvidesPermissions
{
    public static function module(): string
    {
        return 'inventory';
    }

    public static function moduleLabel(): string
    {
        return 'Inventory';
    }

    public static function definitions(): array
    {
        return [
            P::warehouse('inventory.stock.read', 'View stock positions'),
            P::global('inventory.stock.read_company_wide', 'View stock company-wide', '', S::NORMAL, ['inventory.stock.read']),
            P::warehouse('inventory.movement.read', 'View stock movements'),
            P::global('inventory.movement.read_company_wide', 'View stock movements company-wide', '', S::NORMAL, ['inventory.movement.read']),
            P::warehouse('inventory.movement.reverse', 'Reverse posted stock movements', '', S::CRITICAL, ['inventory.movement.read']),
            P::warehouse('inventory.reservation.read', 'View reservations'),
            P::warehouse('inventory.reservation.create', 'Create reservations', '', S::NORMAL, ['inventory.reservation.read']),
            P::warehouse('inventory.reservation.adjust', 'Adjust reservations', '', S::NORMAL, ['inventory.reservation.read']),
            P::warehouse('inventory.reservation.release', 'Release reservations', '', S::NORMAL, ['inventory.reservation.read']),
            P::warehouse('inventory.lot.read', 'View lots'),
            P::warehouse('inventory.lot.register', 'Register lots', '', S::NORMAL, ['inventory.lot.read']),
            P::warehouse('inventory.lot.update', 'Update lot attributes', '', S::NORMAL, ['inventory.lot.read']),
            P::warehouse('inventory.lot.lock', 'Lock lots', '', S::ELEVATED, ['inventory.lot.read']),
            P::warehouse('inventory.lot.unlock', 'Unlock lots', '', S::ELEVATED, ['inventory.lot.read']),
            P::warehouse('inventory.serial.read', 'View serials', '', S::NORMAL, [], 'feature.serial_tracking'),
            P::warehouse('inventory.serial.register', 'Register serials', '', S::NORMAL, ['inventory.serial.read'], 'feature.serial_tracking'),
            P::warehouse('inventory.serial.update', 'Update serial attributes', '', S::NORMAL, ['inventory.serial.read'], 'feature.serial_tracking'),
            P::warehouse('inventory.condition.change', 'Change stock condition', '', S::ELEVATED, ['inventory.stock.read']),
            P::warehouse('inventory.quarantine.release', 'Release stock from quarantine', '', S::ELEVATED, ['inventory.stock.read']),
            P::warehouse('inventory.relocation.create', 'Create relocations', '', S::NORMAL, ['inventory.stock.read']),
            P::warehouse('inventory.relocation.post', 'Post relocations', '', S::NORMAL, ['inventory.relocation.create']),
            P::warehouse('inventory.scrap.create', 'Create scrap documents', '', S::NORMAL, ['inventory.stock.read']),
            P::warehouse('inventory.scrap.approve', 'Approve scrap documents', '', S::ELEVATED, ['inventory.scrap.create']),
            P::warehouse('inventory.scrap.post', 'Post scrap documents', '', S::ELEVATED, ['inventory.scrap.create']),
            P::warehouse('inventory.opening_balance.create', 'Create opening balances', '', S::ELEVATED, ['inventory.stock.read']),
            P::warehouse('inventory.opening_balance.approve', 'Approve opening balances', '', S::CRITICAL, ['inventory.opening_balance.create']),
            P::warehouse('inventory.opening_balance.post', 'Post opening balances', '', S::CRITICAL, ['inventory.opening_balance.create']),
            P::warehouse('inventory.valuation.read', 'View inventory valuation', '', S::ELEVATED),
            P::global('inventory.valuation.read_company_wide', 'View valuation company-wide', '', S::ELEVATED, ['inventory.valuation.read']),
            P::global('inventory.valuation.manage_policy', 'Manage costing policy', '', S::CRITICAL, ['inventory.valuation.read']),
            P::warehouse('inventory.landed_cost.manage', 'Manage landed costs', '', S::ELEVATED, ['inventory.valuation.read'], 'feature.landed_cost'),
            P::global('inventory.reconciliation.run', 'Run projection reconciliation', '', S::ELEVATED),
            P::global('inventory.reconciliation.read', 'View reconciliation results', '', S::ELEVATED),
            P::global('inventory.reconciliation.repair', 'Repair balance projections', '', S::CRITICAL, ['inventory.reconciliation.read']),
            P::warehouse('inventory.trace.read', 'Trace lots and serials'),
            P::global('inventory.trace.read_company_wide', 'Trace company-wide', '', S::NORMAL, ['inventory.trace.read']),
            P::global('inventory.recall.read', 'View recalls', '', S::NORMAL, [], false, 'feature.recalls'),
            P::global('inventory.recall.initiate', 'Initiate recalls', '', S::CRITICAL, ['inventory.recall.read'], false, 'feature.recalls'),
            P::global('inventory.recall.close', 'Close recalls', '', S::ELEVATED, ['inventory.recall.read'], false, 'feature.recalls'),
            P::warehouse('inventory.negative_stock.override', 'Override negative stock prevention', '', S::CRITICAL),
            P::warehouse('inventory.expired_issue.override', 'Issue expired or removal-window stock', '', S::CRITICAL),
        ];
    }
}
