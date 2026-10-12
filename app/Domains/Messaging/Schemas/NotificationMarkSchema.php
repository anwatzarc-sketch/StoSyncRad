<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Schemas;

use App\Domains\Messaging\Enums\NotificationStatus;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Mark own notifications (all when no ids). */
final class NotificationMarkSchema extends TableSchema
{
    public static function table(): string
    {
        return 'notifications';
    }

    protected static function define(): array
    {
        return [
            'ids' => Property::arrayOf(Property::uuid())->nullable()->max(500)->virtual()->label('Notifications'),
            'status' => Property::enum(NotificationStatus::class)->default(NotificationStatus::READ->value)->virtual()->label('Mark as'),
        ];
    }
}
