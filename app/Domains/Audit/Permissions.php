<?php

declare(strict_types=1);

namespace App\Domains\Audit;

use App\Domains\IdentityAccess\Enums\PermissionSensitivity as S;
use App\Domains\IdentityAccess\Permissions\PermissionDefinition as P;
use App\Domains\IdentityAccess\Permissions\ProvidesPermissions;

final class Permissions implements ProvidesPermissions
{
    public static function module(): string
    {
        return 'audit';
    }

    public static function moduleLabel(): string
    {
        return 'Audit & Governance';
    }

    public static function definitions(): array
    {
        return [
            P::global('audit.event.read', 'View audit log', '', S::ELEVATED),
            P::global('audit.event.export', 'Export audit log', '', S::CRITICAL, ['audit.event.read']),
            P::warehouse('audit.approval.read', 'View approval history'),
        ];
    }
}
