<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Permissions;

interface ProvidesPermissions
{
    /** Module code used for grouping in the role builder (e.g. "inventory"). */
    public static function module(): string;

    /** Human label for the module. */
    public static function moduleLabel(): string;

    /** @return array<int, PermissionDefinition> */
    public static function definitions(): array;
}
