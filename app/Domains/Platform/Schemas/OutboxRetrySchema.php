<?php

declare(strict_types=1);

namespace App\Domains\Platform\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Retry dead-lettered outbox events (all when no ids). */
final class OutboxRetrySchema extends TableSchema
{
    public static function table(): string
    {
        return 'outbox_events';
    }

    protected static function define(): array
    {
        return [
            'ids' => Property::arrayOf(Property::uuid())->nullable()->max(1000)->virtual()->label('Events'),
        ];
    }
}
