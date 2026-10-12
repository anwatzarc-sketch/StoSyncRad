<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess;

use App\Domains\IdentityAccess\Enums\PermissionScopeKind as K;
use App\Domains\IdentityAccess\Enums\PermissionSensitivity as S;
use App\Domains\IdentityAccess\Permissions\PermissionDefinition as P;
use App\Domains\IdentityAccess\Permissions\ProvidesPermissions;

final class Permissions implements ProvidesPermissions
{
    public static function module(): string
    {
        return 'identity';
    }

    public static function moduleLabel(): string
    {
        return 'Identity & Access';
    }

    public static function definitions(): array
    {
        return [
            ...P::crud('identity.person', 'persons', ['read', 'create', 'update', 'archive']),
            ...P::crud('identity.account', 'user accounts', ['read', 'create', 'update', 'activate', 'lock', 'suspend', 'deactivate', 'reset_mfa', 'force_password_change', 'revoke_sessions'], K::GLOBAL, [
                'create' => ['sensitivity' => S::ELEVATED], 'update' => ['sensitivity' => S::ELEVATED], 'activate' => ['sensitivity' => S::ELEVATED],
                'lock' => ['sensitivity' => S::ELEVATED], 'suspend' => ['sensitivity' => S::ELEVATED], 'deactivate' => ['sensitivity' => S::CRITICAL],
                'reset_mfa' => ['sensitivity' => S::CRITICAL], 'force_password_change' => ['sensitivity' => S::ELEVATED], 'revoke_sessions' => ['sensitivity' => S::ELEVATED],
            ]),
            P::own('identity.account.read_own', 'View own account'),
            P::own('identity.account.update_own', 'Update own profile'),
            P::own('identity.account.change_own_password', 'Change own password'),
            P::own('identity.account.manage_own_mfa', 'Manage own MFA'),
            P::own('identity.account.manage_own_sessions', 'Manage own sessions'),
            ...P::crud('identity.role', 'roles', ['read', 'create', 'update', 'archive', 'clone'], K::GLOBAL, [
                'create' => ['sensitivity' => S::CRITICAL], 'update' => ['sensitivity' => S::CRITICAL], 'archive' => ['sensitivity' => S::CRITICAL], 'clone' => ['sensitivity' => S::CRITICAL],
            ]),
            ...P::crud('identity.assignment', 'role assignments', ['read', 'create', 'update', 'revoke'], K::GLOBAL, [
                'create' => ['sensitivity' => S::CRITICAL], 'update' => ['sensitivity' => S::CRITICAL], 'revoke' => ['sensitivity' => S::CRITICAL],
            ]),
            P::global('identity.assignment.grant_any_scope', 'Grant scopes the assigner does not hold', '', S::CRITICAL, ['identity.assignment.create']),
            P::global('identity.sod_rule.read', 'View separation-of-duties rules'),
            P::global('identity.sod_rule.manage', 'Manage separation-of-duties rules', '', S::CRITICAL, ['identity.sod_rule.read']),
            P::global('identity.approval_policy.read', 'View approval policies'),
            P::global('identity.approval_policy.manage', 'Manage approval policies', '', S::CRITICAL, ['identity.approval_policy.read']),
            P::global('identity.api_token.read', 'View API tokens', '', S::ELEVATED),
            P::global('identity.api_token.issue', 'Issue API tokens', '', S::ELEVATED, ['identity.api_token.read']),
            P::global('identity.api_token.revoke', 'Revoke API tokens', '', S::ELEVATED, ['identity.api_token.read']),
            P::own('identity.api_token.manage_own', 'Manage own API tokens'),
            P::global('identity.security_event.read', 'View security events', '', S::ELEVATED),
            P::global('identity.access_review.export', 'Export access review', '', S::CRITICAL),
            P::global('identity.sod.override', 'Override recommended separation-of-duties rules', '', S::CRITICAL),
        ];
    }
}
