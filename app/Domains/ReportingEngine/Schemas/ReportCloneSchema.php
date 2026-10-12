<?php

declare(strict_types=1);

namespace App\Domains\ReportingEngine\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Copy a report definition. */
final class ReportCloneSchema extends TableSchema
{
    public static function table(): string
    {
        return 'report_definitions';
    }

    protected static function define(): array
    {
        return [
            'name' => Property::string(190)->nullable()->trim()->virtual()->label('Name of the copy'),
        ];
    }
}
