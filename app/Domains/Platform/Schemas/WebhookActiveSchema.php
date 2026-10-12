<?php

declare(strict_types=1);

namespace App\Domains\Platform\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Enable or pause a webhook endpoint. */
final class WebhookActiveSchema extends TableSchema
{
    public static function table(): string
    {
        return 'webhook_endpoints';
    }

    protected static function define(): array
    {
        return [
            'is_active' => Property::boolean()->required()->virtual()->label('Active'),
        ];
    }
}
