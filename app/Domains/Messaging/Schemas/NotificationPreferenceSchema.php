<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Schemas;

use App\Domains\Messaging\Enums\NotificationChannel;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Own delivery preference for one event type. */
final class NotificationPreferenceSchema extends TableSchema
{
    public static function table(): string
    {
        return 'notification_preferences';
    }

    protected static function define(): array
    {
        return [
            'event_type' => Property::string(120)->required()->pattern('/^[a-z_]+(\.[a-z_]+)+$/')->virtual()->label('Event'),
            'channels' => Property::arrayOf(Property::enum(NotificationChannel::class))->nullable()->max(5)->virtual()->label('Channels'),
            'is_muted' => Property::boolean()->nullable()->virtual()->label('Muted'),
        ];
    }
}
