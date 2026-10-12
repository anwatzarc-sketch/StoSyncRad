<?php

declare(strict_types=1);

namespace App\Domains\Transfers;

use App\Domains\IdentityAccess\Enums\PermissionSensitivity as S;
use App\Domains\IdentityAccess\Permissions\PermissionDefinition as P;
use App\Domains\IdentityAccess\Permissions\ProvidesPermissions;

final class Permissions implements ProvidesPermissions
{
    public static function module(): string
    {
        return 'transfers';
    }

    public static function moduleLabel(): string
    {
        return 'Inter-branch Transfers';
    }

    public static function definitions(): array
    {
        return [
            P::warehouse('transfers.transfer.read', 'View transfers (source or destination)'),
            P::global('transfers.transfer.read_company_wide', 'View transfers company-wide', '', S::NORMAL, ['transfers.transfer.read']),
            P::warehouse('transfers.transfer.create', 'Create transfers', '', S::NORMAL, ['transfers.transfer.read']),
            P::warehouse('transfers.transfer.update', 'Update transfers', '', S::NORMAL, ['transfers.transfer.read']),
            P::warehouse('transfers.transfer.submit', 'Submit transfers', '', S::NORMAL, ['transfers.transfer.read']),
            P::warehouse('transfers.transfer.cancel', 'Cancel transfers', '', S::NORMAL, ['transfers.transfer.read']),
            P::warehouse('transfers.transfer.approve', 'Approve transfers', '', S::ELEVATED, ['transfers.transfer.read']),
            P::warehouse('transfers.transfer.pick', 'Pick transfers (source)', '', S::NORMAL, ['transfers.transfer.read']),
            P::warehouse('transfers.transfer.dispatch', 'Dispatch transfers (source)', '', S::ELEVATED, ['transfers.transfer.read']),
            P::warehouse('transfers.transfer.receive', 'Receive transfers (destination)', '', S::ELEVATED, ['transfers.transfer.read']),
            P::warehouse('transfers.transfer.close', 'Close transfers', '', S::ELEVATED, ['transfers.transfer.read']),
            P::warehouse('transfers.discrepancy.read', 'View transfer discrepancies'),
            P::warehouse('transfers.discrepancy.investigate', 'Investigate discrepancies', '', S::NORMAL, ['transfers.discrepancy.read']),
            P::warehouse('transfers.discrepancy.resolve', 'Resolve discrepancies', '', S::ELEVATED, ['transfers.discrepancy.read']),
            P::warehouse('transfers.discrepancy.accept', 'Accept discrepancies without resolution', '', S::CRITICAL, ['transfers.discrepancy.read']),
        ];
    }
}
