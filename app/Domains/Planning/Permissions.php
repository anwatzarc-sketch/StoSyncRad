<?php

declare(strict_types=1);

namespace App\Domains\Planning;

use App\Domains\IdentityAccess\Enums\PermissionSensitivity as S;
use App\Domains\IdentityAccess\Permissions\PermissionDefinition as P;
use App\Domains\IdentityAccess\Permissions\ProvidesPermissions;

final class Permissions implements ProvidesPermissions
{
    public static function module(): string
    {
        return 'planning';
    }

    public static function moduleLabel(): string
    {
        return 'Planning & Replenishment';
    }

    public static function definitions(): array
    {
        return [
            P::warehouse('planning.demand.read', 'View demand history'),
            P::warehouse('planning.forecast_input.manage', 'Manage forecast inputs', '', S::NORMAL, ['planning.demand.read']),
            P::warehouse('planning.replenishment.read', 'View replenishment suggestions'),
            P::warehouse('planning.replenishment.convert', 'Convert suggestions into requests/transfers', '', S::NORMAL, ['planning.replenishment.read']),
            P::global('planning.allocation_plan.read', 'View allocation plans', '', S::NORMAL, [], false, 'feature.allocation_planning'),
            P::global('planning.allocation_plan.create', 'Create allocation plans', '', S::NORMAL, ['planning.allocation_plan.read'], false, 'feature.allocation_planning'),
            P::global('planning.allocation_plan.update', 'Update allocation plans', '', S::NORMAL, ['planning.allocation_plan.read'], false, 'feature.allocation_planning'),
            P::global('planning.allocation_plan.submit', 'Submit allocation plans', '', S::NORMAL, ['planning.allocation_plan.read'], false, 'feature.allocation_planning'),
            P::global('planning.allocation_plan.approve', 'Approve allocation plans', '', S::ELEVATED, ['planning.allocation_plan.read'], false, 'feature.allocation_planning'),
            P::global('planning.allocation_plan.release', 'Release allocation plans (generate transfers)', '', S::ELEVATED, ['planning.allocation_plan.read'], false, 'feature.allocation_planning'),
            P::global('planning.allocation_plan.cancel', 'Cancel allocation plans', '', S::NORMAL, ['planning.allocation_plan.read'], false, 'feature.allocation_planning'),
        ];
    }
}
