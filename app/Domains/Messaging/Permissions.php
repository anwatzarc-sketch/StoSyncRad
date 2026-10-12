<?php

declare(strict_types=1);

namespace App\Domains\Messaging;

use App\Domains\IdentityAccess\Enums\PermissionSensitivity as S;
use App\Domains\IdentityAccess\Permissions\PermissionDefinition as P;
use App\Domains\IdentityAccess\Permissions\ProvidesPermissions;

final class Permissions implements ProvidesPermissions
{
    public static function module(): string
    {
        return 'messaging';
    }

    public static function moduleLabel(): string
    {
        return 'Notifications & Integrations';
    }

    public static function definitions(): array
    {
        return [
            P::own('messaging.notification.read_own', 'Read own notifications'),
            P::own('messaging.notification.manage_own_preferences', 'Manage own notification preferences'),
            P::global('messaging.webhook.read', 'View webhooks', '', S::ELEVATED),
            P::global('messaging.webhook.manage', 'Manage webhooks', '', S::CRITICAL, ['messaging.webhook.read']),
            P::global('messaging.outbox.read', 'View outbox', '', S::ELEVATED),
            P::global('messaging.outbox.retry', 'Retry dead outbox events', '', S::ELEVATED, ['messaging.outbox.read']),
            P::global('messaging.carrier_webhook.receive', 'Receive carrier status webhooks (integration)', '', S::NORMAL, [], false, 'feature.ecommerce_fulfillment'),
        ];
    }
}
