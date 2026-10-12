<?php

declare(strict_types=1);

namespace App\Domains\Platform\Config\Keys;

use App\Domains\Messaging\Enums\NotificationChannel;
use App\Domains\Platform\Config\DefinesConfigKeys;
use App\Domains\Platform\Config\ProvidesConfigKeys;

final class MessagingKeys implements ProvidesConfigKeys
{
    use DefinesConfigKeys;

    /** Notification events whose delivery channels are configurable. */
    public const EVENTS = [
        'approval_pending', 'approval_decided', 'receipt_requires_inspection', 'transfer_awaiting_dispatch', 'transfer_awaiting_receipt',
        'transfer_discrepancy', 'transfer_overdue', 'issue_ready_for_picking', 'adjustment_requires_approval', 'stocktake_assigned',
        'low_stock', 'expiry_warning', 'reconciliation_failed', 'report_run_completed', 'report_run_failed', 'import_completed',
    ];

    public static function definitions(): array
    {
        $defs = [
            self::str('messaging.mail.from_name', '', 'Mail sender name', 'Sender display name for emails (blank = brand app name).', 'G', ['max_length' => 120]),
            self::str('messaging.mail.from_address', '', 'Mail sender address', 'Sender email address (blank = environment default).', 'G', ['max_length' => 254]),
            self::bool('messaging.digest.enabled', false, 'Daily digest', 'Send a daily digest instead of individual emails.'),
            self::time('messaging.digest.time', '07:00', 'Digest time', 'Local time the daily digest is sent.'),
            self::bool('messaging.push.enabled', false, 'Web push', 'Allow web push notifications for operational events.'),
            self::int('messaging.outbox.max_attempts', 8, 'Outbox attempts', 'Delivery attempts before an outbox event is marked dead.', 'G', 1, 50),
            self::bool('integration.webhooks.enabled', false, 'Webhooks', 'Deliver outbox events to registered webhook endpoints.'),
            self::int('integration.webhooks.max_attempts', 8, 'Webhook attempts', 'Delivery attempts per webhook before dead-lettering.', 'G', 1, 50),
            self::bool('integration.ecommerce.enabled', false, 'E-commerce integration', 'Accept e-commerce order intake from service accounts.'),
            self::bool('integration.pos.enabled', false, 'POS integration', 'Accept retail sale intake from POS service accounts.'),
            self::bool('integration.carrier.enabled', false, 'Carrier integration', 'Accept signed carrier status webhooks.'),
            self::secret('integration.carrier.webhook_secret', 'Carrier webhook secret', 'Shared secret used to verify carrier webhook signatures.'),
        ];
        foreach (self::EVENTS as $event) {
            $defs[] = self::list("messaging.events.{$event}.channels", ['in_app'], 'Channels: '.str_replace('_', ' ', $event),
                'Delivery channels for this notification event.', 'G', NotificationChannel::class);
        }

        return $defs;
    }
}
