<?php

declare(strict_types=1);

namespace App\Domains\Platform\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Switch maintenance mode. */
final class MaintenanceSchema extends TableSchema
{
    public static function table(): string
    {
        return 'configuration_entries';
    }

    protected static function define(): array
    {
        return [
            'enabled' => Property::boolean()->required()->virtual()->label('Maintenance mode'),
            'reason' => Property::text(2000)->nullable()->trim()->virtual()->label('Reason'),
        ];
    }
}
