<?php

declare(strict_types=1);

namespace App\Domains\Partners;

use App\Domains\IdentityAccess\Enums\PermissionScopeKind as K;
use App\Domains\IdentityAccess\Enums\PermissionSensitivity as S;
use App\Domains\IdentityAccess\Permissions\PermissionDefinition as P;
use App\Domains\IdentityAccess\Permissions\ProvidesPermissions;

final class Permissions implements ProvidesPermissions
{
    public static function module(): string
    {
        return 'partners';
    }

    public static function moduleLabel(): string
    {
        return 'Partners';
    }

    public static function definitions(): array
    {
        return [
            ...P::crud('partners.partner', 'partners', ['read', 'create', 'update', 'hold', 'blacklist', 'archive'], K::GLOBAL, ['blacklist' => ['sensitivity' => S::ELEVATED]]),
            P::global('partners.carrier.read', 'View carriers'),
            P::global('partners.carrier.manage', 'Manage carriers', '', S::NORMAL, ['partners.carrier.read']),
        ];
    }
}
