<?php

declare(strict_types=1);

namespace App\Domains\Platform\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Register an outbound webhook endpoint. */
final class WebhookRegisterSchema extends TableSchema
{
    public static function table(): string
    {
        return 'webhook_endpoints';
    }

    protected static function define(): array
    {
        return [
            'name' => Property::string(120)->required()->trim()->virtual()->label('Name'),
            'url' => Property::url()->required()->virtual()->label('Endpoint URL'),
            'event_types' => Property::arrayOf(Property::string(120)->pattern('/^[a-z_]+(\.[a-z_]+)+$/'))->required()->min(1)->max(200)->virtual()->label('Events'),
        ];
    }
}
