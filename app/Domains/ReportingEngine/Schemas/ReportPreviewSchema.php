<?php

declare(strict_types=1);

namespace App\Domains\ReportingEngine\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Preview an unsaved report definition. */
final class ReportPreviewSchema extends TableSchema
{
    public static function table(): string
    {
        return 'report_runs';
    }

    protected static function define(): array
    {
        return [
            'dataset_code' => Property::string(120)->required()->pattern('/^[a-z][a-z0-9_]{1,119}$/')->virtual()->label('Dataset'),
            'definition' => Property::json()->required()->virtual()->label('Definition'),
            'parameters' => Property::json()->nullable()->virtual()->label('Parameters'),
            'per_page' => Property::integer()->nullable()->min(1)->max(200)->virtual()->label('Rows'),
        ];
    }
}
